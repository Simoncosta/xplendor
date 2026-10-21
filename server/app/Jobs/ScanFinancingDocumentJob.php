<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FinancingDocument;
use App\Services\AlertService;
use App\Services\Financing\Antivirus\VirusScanner;
use App\Services\Financing\FinancingFiles;
use App\Services\Leads\LeadRecipients;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Analisa no antivírus um documento de financiamento acabado de chegar. Limpo: passa a poder
 * descarregar-se. Infetado: o ficheiro é apagado (fica o registo, "recusado" na página do
 * cliente) e o vendedor é avisado no sino. Sem resposta do analisador: tenta outra vez; até lá,
 * o documento fica "em análise" e não se descarrega.
 */
class ScanFinancingDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $documentId) {}

    public function handle(VirusScanner $scanner, AlertService $alerts): void
    {
        $doc = FinancingDocument::with('storedFile', 'link.car')->find($this->documentId);
        if (! $doc || $doc->scan_status !== FinancingDocument::SCAN_PENDING || ! $doc->storedFile) {
            return;
        }
        $result = $scanner->scan($doc->storedFile->disk, $doc->storedFile->path);
        if ($result === VirusScanner::CLEAN) {
            $doc->forceFill(['scan_status' => FinancingDocument::SCAN_CLEAN, 'scanned_at' => now()])->save();

            return;
        }
        FinancingFiles::deleteFile($doc, keepRow: true);
        $doc->forceFill(['scan_status' => FinancingDocument::SCAN_INFECTED, 'scanned_at' => now()])->save();
        Log::warning('[Financiamento] Documento recusado pelo antivírus', ['document_id' => $doc->id, 'link_id' => $doc->financing_link_id]);
        $car = $doc->link?->car;
        $alerts->createSystemAlert((int) $doc->company_id, 'warning', 'Documento recusado pelo antivírus',
            'Um ficheiro enviado para ' . LeadRecipients::carLabel($car) . " (pedido n.º {$doc->financing_link_id}) foi recusado pelo antivírus e apagado. Peça ao cliente que o envie de novo.",
            'high', $car ? "/cars/{$car->id}/ficha" : '/ficheiros', ownOnly: true);
    }
}
