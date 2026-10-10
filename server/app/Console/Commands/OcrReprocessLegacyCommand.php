<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OcrInvoice;
use App\Services\InvoiceOcrService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * XPLENDOR — F2c: reprocessa pelo pipeline da F2a (QR primeiro, linhas pela IA, conferência) as
 * faturas ANTIGAS, sem dados do QR, que ainda estão por validar ou em erro. As validadas não se tocam.
 *
 *   docker exec xplendor-worker php artisan ocr:reprocess-legacy 5 --dry-run
 *   docker exec xplendor-worker php artisan ocr:reprocess-legacy 5 --invoice=1 --invoice=3
 *
 * --dry-run: só lê o ficheiro (scraper, SEM IA) e mostra a lista, o caminho (texto/imagem), o
 * modelo e o custo ESTIMADO (sem contar uma eventual 2.ª tentativa). Corre no worker (docker).
 */
class OcrReprocessLegacyCommand extends Command
{
    protected $signature = 'ocr:reprocess-legacy
        {company : ID da empresa}
        {--dry-run : Só mostra a lista e o custo estimado (não chama a IA)}
        {--invoice=* : Só estas faturas (ids)}';

    protected $description = 'Reprocessa as faturas OCR antigas (sem QR) que estão por validar ou em erro.';

    public function handle(InvoiceOcrService $ocr): int
    {
        $companyId = (int) $this->argument('company');
        $q = OcrInvoice::where('company_id', $companyId)->whereNull('qr_ok')->whereNull('qr_raw')
            ->whereIn('status', ['por_validar', 'erro'])->orderBy('id');
        if ($ids = $this->option('invoice')) {
            $q->whereIn('id', array_map('intval', $ids));
        }
        $invoices = $q->get();
        if ($invoices->isEmpty()) {
            $this->info('Nenhuma fatura antiga por reprocessar.');

            return self::SUCCESS;
        }
        $disk = Storage::disk((string) config('services.openai.ocr_disk', 'local'));

        if ($this->option('dry-run')) {
            $rows = [];
            $total = 0.0;
            foreach ($invoices as $inv) {
                $bytes = $inv->image_path && $disk->exists($inv->image_path) ? (string) $disk->get($inv->image_path) : null;
                if ($bytes === null) {
                    $rows[] = [$inv->id, $inv->number, $inv->status, '—', '—', '—', 'ficheiro em falta'];
                    continue;
                }
                $i = $ocr->inspect($bytes, (string) $inv->image_mime, (string) $inv->image_path);
                [$in, $out] = $i['lines_source'] === 'texto'
                    ? [600 + (int) round($i['text_chars'] * 0.65), 100 + (int) round($i['text_chars'] * 0.5)]
                    : [700 + 2900 * $i['pages'], 1000 * $i['pages']];
                $cost = $ocr->costUsd((string) $i['model'], $in, $out);
                $total += (float) $cost;
                $rows[] = [$inv->id, $inv->number, $inv->status, $i['qr'] ? 'sim' : 'não', "{$i['lines_source']} · {$i['pages']} pág.", ($i['provider'] ?? 'openai') . " · {$i['model']}",
                    $cost === null ? '—' : sprintf('%.4f', $cost)];
            }
            $this->table(['#', 'fatura', 'estado', 'QR', 'linhas', 'modelo', 'USD (est.)'], $rows);
            $this->info(sprintf('%d faturas · custo estimado %.4f USD (sem contar 2.ª tentativas). Nada foi alterado.', count($rows), $total));

            return self::SUCCESS;
        }

        $rows = [];
        $cost = 0.0;
        foreach ($invoices as $inv) {
            $before = $this->snapshot($inv);
            $inv->update(['status' => 'processing', 'error_message' => null]);
            try {
                $ocr->process($inv->id);
            } catch (\Throwable $e) {
                $this->warn("#{$inv->id}: falhou — {$e->getMessage()}");
            }
            $after = $this->snapshot($inv->fresh());
            $cost += (float) $inv->fresh()->cost_usd;
            $rows[] = [$inv->id, "{$before['nif']} → {$after['nif']}", "{$before['total']} → {$after['total']}",
                "{$before['link']} → {$after['link']}", "{$before['status']} → {$after['status']}", $after['check'], $after['cost']];
        }
        $this->table(['#', 'NIF', 'total', 'estado F3', 'estado', 'conferência', 'USD'], $rows);
        $this->info(sprintf('Custo total: %.6f USD.', $cost));

        return self::SUCCESS;
    }

    private function snapshot(OcrInvoice $inv): array
    {
        return [
            'nif'    => $inv->supplier_nif ?? '—',
            'total'  => $inv->summary ? number_format($inv->summary->total_cents / 100, 2, ',', '') : '—',
            'link'   => $inv->link_status ?? '—',
            'status' => $inv->status,
            'check'  => $inv->check_status ?? '—',
            'cost'   => $inv->cost_usd !== null ? sprintf('%.6f', $inv->cost_usd) : '—',
        ];
    }
}
