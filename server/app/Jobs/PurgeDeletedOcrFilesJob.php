<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\OcrInvoiceDeleteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — F2c: madrugada, apaga do disco os ficheiros das faturas OCR apagadas há mais de
 * 30 dias (o registo fica, apagado; já não se pode repor).
 */
class PurgeDeletedOcrFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function handle(OcrInvoiceDeleteService $deleter): void
    {
        $n = $deleter->purgeFiles();
        Log::info('[OCR] ficheiros de faturas apagadas purgados', ['count' => $n]);
    }
}
