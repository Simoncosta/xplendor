<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Apagamento DEFINITIVO de uma empresa arquivada (90 dias sem admin nem agência).
 *
 * Saem para sempre os dados de trabalho: publicações e versões, ficheiros no disco, Perfil da
 * Marca, ligações e tokens, colaboradores, utilizadores (incluindo os pendentes) e tudo o resto
 * que tem company_id.
 *
 * Ficam APENAS os registos que a lei obriga a guardar: as cobranças e faturas da XPLENDOR
 * (10 anos), com a despesa, a categoria e o fornecedor a que cada uma está presa, os ficheiros
 * das faturas, e a identificação mínima da empresa (NIPC e nomes). Ficam também os registos da
 * própria plataforma sobre a empresa (orçamentos da XPLENDOR, histórico das relações de gestão,
 * sessões de impersonation).
 */
class CompanyPurgeService
{
    /** Tabelas com company_id que NÃO se apagam (registos da plataforma ou obrigatórios por lei). */
    private const KEEP_TABLES = [
        'companies', 'expense_charges', 'quotes', 'impersonation_sessions', 'managed_company_requests',
        // Tratadas à parte: só saem as linhas que não estão presas a uma cobrança.
        'expenses', 'expense_categories', 'suppliers',
    ];

    /** Colunas da empresa que ficam (identificação mínima e o estado do arquivo). */
    private const KEEP_COMPANY_COLUMNS = [
        'id', 'nipc', 'fiscal_name', 'trade_name', 'plan_id', 'created_at', 'updated_at', 'deleted_at',
        'archived_at', 'archive_delete_at', 'archive_warned_at', 'purged_at',
    ];

    /** @return array<string, int> linhas apagadas por tabela */
    public function purge(Company $company): array
    {
        $id = $company->id;
        $deleted = [];

        $this->deleteFiles($id);

        $userIds = DB::table('users')->where('company_id', $id)->pluck('id')->all();
        if ($userIds !== []) {
            $deleted['personal_access_tokens'] = DB::table('personal_access_tokens')
                ->where('tokenable_type', \App\Models\User::class)->whereIn('tokenable_id', $userIds)->delete();
        }

        // Dados de trabalho: todas as tabelas com company_id, fora das que ficam. Com as chaves
        // estrangeiras, algumas só saem depois de outras: repete até não haver progresso.
        $pending = array_values(array_diff($this->companyTables(), self::KEEP_TABLES));
        for ($round = 0; $pending !== [] && $round < 10; $round++) {
            $failed = [];
            foreach ($pending as $table) {
                try {
                    $deleted[$table] = ($deleted[$table] ?? 0) + DB::table($table)->where('company_id', $id)->delete();
                } catch (QueryException) {
                    $failed[] = $table;
                }
            }
            if (count($failed) === count($pending)) {
                break;
            }
            $pending = $failed;
        }
        if ($pending !== []) {
            Log::error('[Apagamento] Tabelas que não foi possível limpar.', ['company_id' => $id, 'tables' => $pending]);
        }

        // Despesas, categorias e fornecedores: ficam só os presos às cobranças da XPLENDOR.
        $keptExpenses = DB::table('expense_charges')->where('company_id', $id)->pluck('expense_id')->all();
        $deleted['expenses'] = DB::table('expenses')->where('company_id', $id)->whereNotIn('id', $keptExpenses)->delete();
        $usedCategories = DB::table('expenses')->whereIn('id', $keptExpenses)->whereNotNull('expense_category_id')->pluck('expense_category_id')->all();
        $usedSuppliers = DB::table('expenses')->whereIn('id', $keptExpenses)->whereNotNull('supplier_id')->pluck('supplier_id')->all();
        $deleted['expense_categories'] = DB::table('expense_categories')->where('company_id', $id)->whereNotIn('id', $usedCategories)->delete();
        $deleted['suppliers'] = DB::table('suppliers')->where('company_id', $id)->whereNotIn('id', $usedSuppliers)->delete();

        // A empresa: só a identificação mínima.
        $blank = [];
        foreach (Schema::getColumns('companies') as $col) {
            if (! in_array($col['name'], self::KEEP_COMPANY_COLUMNS, true) && $col['nullable']) {
                $blank[$col['name']] = null;
            }
        }
        DB::table('companies')->where('id', $id)->update($blank + ['purged_at' => now(), 'deleted_at' => $company->deleted_at ?? now(), 'updated_at' => now()]);

        Log::info('[Apagamento] Empresa apagada de forma definitiva (ficam as cobranças da XPLENDOR).', ['company_id' => $id, 'deleted' => array_filter($deleted)]);

        return $deleted;
    }

    /** Ficheiros da empresa em todos os discos, salvo as faturas e os comprovativos da XPLENDOR. */
    private function deleteFiles(int $id): void
    {
        // R2: os ficheiros podem estar no disco configurado (R2) e ainda nos discos locais (a
        // migração não apaga o local): apaga-se nos dois.
        $private = array_unique(['local', (string) config('storage_targets.private_disk', 'local'), (string) config('services.openai.ocr_disk', 'local')]);
        $media = array_unique(['media', (string) config('media.disk', 'media')]);
        $plan = ['public' => ["company_{$id}"]];
        foreach ($private as $disk) {
            $plan[$disk] = array_merge($plan[$disk] ?? [], ["company_{$id}", "ocr-invoices/{$id}", "support-invoices/company_{$id}", "satisfaction-reports/company_{$id}"]);
        }
        foreach ($media as $disk) {
            $plan[$disk] = array_merge($plan[$disk] ?? [], ["company_{$id}", "social/company_{$id}"]);
        }
        foreach ($plan as $disk => $dirs) {
            foreach (array_unique($dirs) as $dir) {
                if (Storage::disk($disk)->exists($dir) || ! \App\Support\Storage\LocalCopy::isLocal(Storage::disk($disk))) {
                    Storage::disk($disk)->deleteDirectory($dir); // num disco S3 não há diretórios: apaga pelo prefixo
                }
            }
        }
        // Os envios em partes por concluir desta empresa (ficavam órfãos no disco local).
        foreach (\App\Models\MediaUpload::where('company_id', $id)->whereNull('completed_at')->get() as $u) {
            Storage::disk('media')->delete($u->tempPath());
        }
    }

    /** @return string[] */
    private function companyTables(): array
    {
        return collect(Schema::getTables())->pluck('name')
            ->filter(fn ($t) => Schema::hasColumn($t, 'company_id'))->values()->all();
    }
}
