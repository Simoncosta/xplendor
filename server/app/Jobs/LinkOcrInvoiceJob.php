<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OcrInvoice;
use App\Services\OcrPingwinLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — F3: liga UMA fatura OCR ao PingWin no WORKER, com a pesquisa viva do fornecedor
 * por NIF (só leitura; o php-fpm não tem o socket Docker). Usado pelo "Procurar no PingWin"
 * e ao validar quando o fornecedor não está no espelho. A UI faz polling (link_search_pending).
 */
class LinkOcrInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 240; // pesquisa por NIF (docker exec) + ligação

    public function __construct(public int $invoiceId) {}

    public function handle(OcrPingwinLinkService $links): void
    {
        $inv = OcrInvoice::find($this->invoiceId);
        if ($inv) {
            $links->link($inv, true);
        }
    }

    public function failed(\Throwable $e): void
    {
        OcrInvoice::whereKey($this->invoiceId)->update(['link_search_pending' => false]);
        Log::error('[OCR↔PingWin] job falhou', ['invoice_id' => $this->invoiceId, 'error' => $e->getMessage()]);
    }
}
