<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Models\OcrInvoice;
use App\Services\OcrLineArticleService;
use App\Services\SupplierArticleMapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — F2b: madrugada, DEPOIS das linhas da F4 (07:15) e da revisão F3 (07:45): atualiza o
 * mapa de aprendizagem e volta a ligar as linhas das faturas por validar (as manuais ficam).
 * Só a nossa BD — não fala com o PingWin.
 */
class RefreshSupplierArticleMapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function handle(SupplierArticleMapService $map, OcrLineArticleService $lines): void
    {
        $companyIds = CompanyIntegration::where('platform', 'pingwin')->where('status', '!=', 'revoked')
            ->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        foreach ($companyIds as $companyId) {
            $stats = $map->bootstrap($companyId);
            $relinked = 0;
            OcrInvoice::where('company_id', $companyId)->where('status', 'por_validar')->orderBy('id')
                ->each(function (OcrInvoice $inv) use ($lines, &$relinked) {
                    $lines->linkInvoice($inv);
                    $relinked++;
                });
            Log::info('[OCR Artigos] mapa atualizado', ['company_id' => $companyId, 'pairs' => $stats['pairs'],
                'conflicts' => $stats['conflicts'], 'invoices_relinked' => $relinked]);
        }
    }
}
