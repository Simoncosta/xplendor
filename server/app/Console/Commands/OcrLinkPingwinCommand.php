<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OcrInvoice;
use App\Services\InvoiceOcrService;
use App\Services\OcrPingwinLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * XPLENDOR — F3: liga as faturas OCR de uma empresa (ou uma) aos documentos do PingWin e mostra
 * o resultado. Só espelhos; com --live pesquisa o fornecedor por NIF no PingWin (só leitura).
 *
 *   docker exec xplendor-worker php artisan ocr:link-pingwin 5 --live
 *   docker exec xplendor-worker php artisan ocr:link-pingwin 5 --invoice=4 --extract-guides
 *
 * --extract-guides: para faturas já lidas antes da F3, relê o PDF no scraper (SEM IA) e guarda
 * as guias referidas no texto (GT/GR/GD + nº + data).
 */
class OcrLinkPingwinCommand extends Command
{
    protected $signature = 'ocr:link-pingwin
        {company : ID da empresa (ex.: 5 = Yuko)}
        {--invoice= : Só esta fatura OCR}
        {--live : Pesquisa viva do fornecedor por NIF no PingWin (só leitura; só no worker)}
        {--extract-guides : Relê o texto do PDF (sem IA) para as faturas sem guias guardadas}';

    protected $description = 'Liga as faturas OCR aos documentos de fornecedor do PingWin (espelhos).';

    public function handle(OcrPingwinLinkService $links, InvoiceOcrService $ocr): int
    {
        $companyId = (int) $this->argument('company');
        $q = OcrInvoice::where('company_id', $companyId)->orderBy('id');
        if ($id = $this->option('invoice')) {
            $q->whereKey((int) $id);
        }
        $invoices = $q->get();
        if ($invoices->isEmpty()) {
            $this->error('Sem faturas.');

            return self::FAILURE;
        }

        $rows = [];
        foreach ($invoices as $inv) {
            if ($this->option('extract-guides') && $inv->guide_refs === null && str_contains(strtolower((string) $inv->image_mime . $inv->image_path), 'pdf')) {
                $bytes = Storage::disk((string) config('services.openai.ocr_disk', 'local'))->get($inv->image_path);
                $guides = $bytes ? OcrPingwinLinkService::extractGuides((string) ($ocr->analyzeText($bytes) ?? '')) : [];
                $inv->update(['guide_refs' => $guides ?: null]);
            }
            $links->link($inv, (bool) $this->option('live'));
            $inv->refresh();
            $p = $links->present($inv);
            $docs = $p['linked'] ?: $p['candidates'];
            $rows[] = [
                $inv->id, $inv->number, $p['supplier']['name'] ?? '—', $inv->status, $inv->link_status ?? '—',
                collect($docs)->map(fn ($d) => $d['document'])->take(4)->implode(', ') . (count($docs) > 4 ? ' … (' . count($docs) . ')' : ''),
                collect($p['linked'])->pluck('method')->unique()->implode(',') ?: ($p['candidates_mode'] ?? '—'),
                $p['diff'] === null ? (count($p['candidates']) > 1 && $p['candidates_mode'] === 'guias'
                    ? sprintf('Σ %.2f vs %.2f', collect($p['candidates'])->sum('total'), $p['invoice_total']) : '—') : sprintf('%+.2f', $p['diff']),
            ];
        }
        $this->table(['#', 'fatura', 'fornecedor', 'estado OCR', 'PingWin', 'documento(s)', 'método', 'diferença'], $rows);

        return self::SUCCESS;
    }
}
