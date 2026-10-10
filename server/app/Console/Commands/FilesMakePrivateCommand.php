<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SatisfactionReportPhoto;
use App\Models\SupportTicket;
use App\Support\Storage\PrivateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Pré-deploy, ponto 5: as faturas dos pedidos de suporte e as fotografias dos relatórios de
 * satisfação saem do disco público (/storage) para o disco privado. SIMULAÇÃO por omissão.
 * Com --execute, cada ficheiro é copiado, confirmado (tamanho e SHA-256), a referência na base
 * de dados passa ao caminho privado e só então o público é apagado. Idempotente: o que já está
 * no disco privado não volta a ser tocado.
 *   php artisan files:make-private             → simulação
 *   php artisan files:make-private --execute   → move
 */
class FilesMakePrivateCommand extends Command
{
    protected $signature = 'files:make-private {--execute : Move de facto (sem isto, só simula)}';

    protected $description = 'Passa as faturas dos pedidos de suporte e as fotografias dos relatórios de satisfação para o disco privado.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $this->line($execute ? 'A mover para o disco privado…' : 'SIMULAÇÃO (nada muda). Use --execute para mover.');
        $stats = ['faturas' => 0, 'fotografias' => 0, 'em_falta' => 0, 'falhas' => 0];

        foreach (SupportTicket::where('invoice_path', 'like', '/storage/%')->orderBy('id')->cursor() as $t) {
            $target = "support-invoices/company_{$t->company_id}/" . basename($t->invoice_path);
            $r = $this->move($t->invoice_path, $target, $execute, fn (int $size) => $t->forceFill(['invoice_path' => $target, 'invoice_size_bytes' => $size])->saveQuietly());
            $stats[$r === 'ok' ? 'faturas' : $r]++;
        }
        foreach (SatisfactionReportPhoto::where('path', 'like', '/storage/%')->with('report')->orderBy('id')->cursor() as $p) {
            $companyId = $p->report?->company_id ?? 0;
            $target = "satisfaction-reports/company_{$companyId}/{$p->satisfaction_report_id}/" . basename($p->path);
            $r = $this->move($p->path, $target, $execute, fn (int $size) => $p->forceFill(['path' => $target, 'size_bytes' => $size])->saveQuietly());
            $stats[$r === 'ok' ? 'fotografias' : $r]++;
        }

        $this->table(['Faturas', 'Fotografias', 'Em falta no disco', 'Falhas'], [[$stats['faturas'], $stats['fotografias'], $stats['em_falta'], $stats['falhas']]]);

        return $stats['falhas'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function move(string $publicUrl, string $target, bool $execute, \Closure $updateReference): string
    {
        $source = ltrim(substr($publicUrl, strlen('/storage')), '/');
        $public = Storage::disk('public');
        if (! $public->exists($source)) {
            $this->warn("Em falta: {$publicUrl}");

            return 'em_falta';
        }
        if (! $execute) {
            $this->output->isVerbose() && $this->line("  {$publicUrl} → {$target}");

            return 'ok';
        }
        $private = Storage::disk(PrivateFiles::disk());
        $bytes = (string) $public->get($source);
        $private->put($target, $bytes);
        if ((string) $private->get($target) !== $bytes) {
            $this->error("Falhou a confirmação: {$publicUrl}");

            return 'falhas';
        }
        $updateReference(strlen($bytes)); // com o tamanho, para o espaço por empresa
        $public->delete($source);

        return 'ok';
    }
}
