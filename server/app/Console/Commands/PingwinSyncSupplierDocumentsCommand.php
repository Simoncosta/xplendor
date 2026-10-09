<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\SupplierDocumentsSyncInProgress;
use App\Models\PingwinDocumentSyncRun;
use App\Models\PingwinSupplierDocument;
use App\Services\SupplierDocumentsService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — Documentos de fornecedor (F1, SÓ LEITURA): sync MANUAL e síncrona de um
 * período (por omissão os últimos 7 dias). Regista um run como o botão da página.
 *
 * ⚠️ O invoke() faz `docker exec` ao container do scraper → corre no WORKER:
 *   docker exec xplendor-worker php artisan pingwin:sync-supplier-documents 5 --from=2026-10-05 --to=2026-10-05
 */
class PingwinSyncSupplierDocumentsCommand extends Command
{
    protected $signature = 'pingwin:sync-supplier-documents
        {company : ID da empresa (ex.: 5 = Yuko)}
        {--from= : Início (AAAA-MM-DD); por omissão há 7 dias}
        {--to= : Fim (AAAA-MM-DD); por omissão hoje}';

    protected $description = 'Sincroniza (leitura) os documentos de fornecedor do PingWin num período.';

    public function handle(SupplierDocumentsService $docs): int
    {
        $companyId = (int) $this->argument('company');
        $from = $this->option('from') ?: now('Europe/Lisbon')->subDays(SupplierDocumentsService::NIGHTLY_DAYS)->toDateString();
        $to = $this->option('to') ?: now('Europe/Lisbon')->toDateString();

        try {
            $run = $docs->createRun($companyId, $from, $to, PingwinDocumentSyncRun::TRIGGER_MANUAL);
        } catch (SupplierDocumentsSyncInProgress $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Falhou: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Run {$run->id}: {$from} → {$to} …");
        $t0 = microtime(true);
        $run = $docs->executeRun($run->id);
        $secs = round(microtime(true) - $t0, 1);

        if ($run->status !== PingwinDocumentSyncRun::STATUS_OK) {
            $this->error("Falhou ({$secs}s): {$run->error}");

            return self::FAILURE;
        }
        $this->info("OK — {$run->docs_count} documento(s) em {$secs}s.");

        $rows = PingwinSupplierDocument::where('company_id', $companyId)
            ->whereBetween('doc_date', [$from, $to])
            ->selectRaw('doctype, COUNT(*) as n, SUM(total_cents) as total')
            ->groupBy('doctype')->orderByDesc('n')->get();
        $this->table(['Tipo', 'Documentos', 'Total'], $rows->map(fn ($r) => [
            $r->doctype, $r->n, number_format(((int) $r->total) / 100, 2, ',', '.'),
        ])->all());

        return self::SUCCESS;
    }
}
