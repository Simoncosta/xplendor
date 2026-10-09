<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessInvoiceOcrJob;
use App\Models\OcrInvoice;
use App\Services\InvoiceOcrService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — OCR de faturas (F2a): REPROCESSA uma fatura (QR → texto/imagem → IA →
 * conferência). Por omissão mete o job na fila (como o botão "Reprocessar" da UI);
 * com --sync corre aqui e mostra origem, modelo, tokens, custo e conferência.
 *
 * ⚠️ A leitura do ficheiro faz `docker exec` ao scraper → com --sync corre no WORKER:
 *   docker exec xplendor-worker php artisan ocr:reprocess 12 --sync
 * Uma fatura 'validada' só é reprocessada com --force (perde a validação do utilizador).
 */
class OcrReprocessInvoiceCommand extends Command
{
    protected $signature = 'ocr:reprocess
        {invoice : ID da fatura OCR (ocr_invoices.id)}
        {--sync : Corre já (no worker) e mostra o resultado}
        {--force : Reprocessa mesmo que esteja validada}';

    protected $description = 'Reprocessa uma fatura OCR (QR primeiro + linhas pela IA + conferência pelo QR).';

    public function handle(InvoiceOcrService $ocr): int
    {
        $invoice = OcrInvoice::find((int) $this->argument('invoice'));
        if (! $invoice) {
            $this->error('Fatura não encontrada.');

            return self::FAILURE;
        }
        if ($invoice->status === 'processing' && ! $this->option('force')) {
            $this->error('A fatura já está a ser lida (usa --force se ficou presa).');

            return self::FAILURE;
        }
        if ($invoice->status === 'validada' && ! $this->option('force')) {
            $this->error('A fatura já foi validada — usa --force para a reprocessar (perde a validação).');

            return self::FAILURE;
        }

        $invoice->update(['status' => 'processing', 'error_message' => null]);

        if (! $this->option('sync')) {
            ProcessInvoiceOcrJob::dispatch($invoice->company_id, $invoice->id);
            $this->info("Fatura {$invoice->id} na fila para reprocessar.");

            return self::SUCCESS;
        }

        try {
            $ocr->process($invoice->id);
        } catch (\Throwable $e) {
            $this->error('Falhou: ' . $e->getMessage());

            return self::FAILURE;
        }

        $inv = $invoice->fresh(['lines']);
        $this->table(['campo', 'valor'], [
            ['estado', $inv->status . ($inv->error_message ? " — {$inv->error_message}" : '')],
            ['QR', $inv->qr_ok ? 'sim' : 'não'],
            ['fonte', (string) $inv->source],
            ['modelo', (string) $inv->model],
            ['páginas', (string) $inv->pages],
            ['linhas', (string) $inv->lines->count()],
            ['conferência', (string) $inv->check_status],
            ['tentativas', (string) $inv->attempts],
            ['tokens in/out', "{$inv->tokens_in} / {$inv->tokens_out}"],
            ['custo USD', $inv->cost_usd === null ? '— (modelo sem preço em OCR_PRICES)' : number_format((float) $inv->cost_usd, 6)],
            ['tempo', round(((int) $inv->duration_ms) / 1000, 1) . ' s'],
        ]);
        foreach ($inv->check_diff ?? [] as $r) {
            $this->line(sprintf('  IVA %s%%: QR %.2f · linhas %.2f · dif %+.2f %s',
                $r['rate'] ?? '?', $r['qr_cents'] / 100, $r['lines_cents'] / 100, $r['diff_cents'] / 100, $r['ok'] ? '✓' : '✗'));
        }

        return self::SUCCESS;
    }
}
