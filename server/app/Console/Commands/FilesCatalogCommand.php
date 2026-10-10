<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyModuleService;
use App\Services\Files\SystemFileCatalog;
use Illuminate\Console\Command;

/**
 * Ficheiros: regista no catálogo os ficheiros que os módulos já guardam (pastas do sistema).
 * Nenhum ficheiro é movido, copiado ou apagado: só se regista onde está.
 *
 *   php artisan files:catalog                     simulação (quantos por origem e empresa)
 *   php artisan files:catalog --execute           grava
 *   php artisan files:catalog --company=5         só uma empresa
 *
 * Por omissão, só as empresas com o módulo "Ficheiros" ligado. Idempotente.
 */
class FilesCatalogCommand extends Command
{
    protected $signature = 'files:catalog
        {--execute : Grava (sem isto, só simula)}
        {--company= : Só esta empresa}
        {--all : Todas as empresas, mesmo sem o módulo ligado}';

    protected $description = 'Regista nos Ficheiros os ficheiros que os módulos já guardam (simulação por omissão; nada é copiado).';

    public function handle(SystemFileCatalog $catalog, CompanyModuleService $modules): int
    {
        $execute = (bool) $this->option('execute');
        $this->line($execute ? 'A registar (nada é copiado)…' : 'SIMULAÇÃO (nada muda). Use --execute para gravar.');
        $companies = Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id')->get(['id', 'fiscal_name', 'trade_name']);
        $rows = [];
        $totals = ['total' => 0, 'novos' => 0, 'atualizados' => 0, 'em_falta' => 0, 'sem_ficheiro' => 0];
        foreach ($companies as $c) {
            if (! $this->option('company') && ! $this->option('all') && ! $modules->isEnabled($c->id, 'ficheiros')) {
                continue;
            }
            foreach ($catalog->sync($c->id, $execute) as $source => $s) {
                $rows[] = [$c->id, $c->trade_name ?: $c->fiscal_name, SystemFileCatalog::SOURCES[$source] ?? $source, $s['total'], $s['novos'], $s['atualizados'], $s['em_falta'], $s['sem_ficheiro']];
                foreach ($totals as $k => $v) {
                    $totals[$k] = $v + $s[$k];
                }
            }
        }
        $this->table(['Empresa', 'Nome', 'Origem', 'Ficheiros', 'Novos', 'Atualizados', 'Saíram do módulo', 'Sem ficheiro no disco'], $rows ?: [['', 'Nenhuma empresa com o módulo ligado', '', 0, 0, 0, 0, 0]]);
        $this->line("Total: {$totals['total']} ficheiros; {$totals['novos']} novos, {$totals['atualizados']} atualizados, {$totals['em_falta']} saíram do módulo, {$totals['sem_ficheiro']} sem ficheiro no disco (não registados).");

        return self::SUCCESS;
    }
}
