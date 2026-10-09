<?php

namespace App\Jobs;

use App\Services\AlertService;
use App\Models\OcrInvoice;
use App\Services\InvoiceOcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — OCR de fatura de fornecedor (Fase A): lê a imagem, chama a IA,
 * sanitiza e cria o rascunho ('por_validar') para o utilizador validar. Corre no
 * worker (tem o socket, necessário se a fatura for PDF → scraper/poppler). NÃO
 * escreve no PingWin. Notifica no sino quando termina.
 */
class ProcessInvoiceOcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900; // até 2 tentativas da IA com várias páginas (OCR_HTTP_TIMEOUT cada)

    public function __construct(public int $companyId, public int $invoiceId) {}

    public function handle(InvoiceOcrService $ocr, AlertService $alerts): void
    {
        Log::info('[OCR Fatura] Job iniciado', ['company_id' => $this->companyId, 'invoice_id' => $this->invoiceId]);
        try {
            $ocr->process($this->invoiceId);
        } catch (\Throwable $e) {
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Falha ao ler a fatura',
                message: 'Não foi possível ler a fatura com a IA. Tenta novamente ou envia uma imagem mais nítida.',
                severity: 'high',
                detailPath: '/restauracao/faturas',
            );

            return; // o estado 'erro' já foi gravado no serviço
        }

        if (OcrInvoice::where('id', $this->invoiceId)->value('status') === InvoiceOcrService::STATUS_NOT_OURS) {
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Fatura não é desta empresa',
                message: 'O QR da fatura indica outro adquirente (ou a própria empresa como emitente). Não foi lida pela IA.',
                severity: 'medium',
                detailPath: "/restauracao/faturas/{$this->invoiceId}",
            );

            return;
        }

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Fatura lida pela IA',
            message: 'A fatura foi lida — verifica e valida os dados.',
            severity: 'low',
            detailPath: "/restauracao/faturas/{$this->invoiceId}",
        );
        Log::info('[OCR Fatura] Job concluído', ['invoice_id' => $this->invoiceId]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[OCR Fatura] Job falhou', ['invoice_id' => $this->invoiceId, 'error' => $e->getMessage()]);
    }
}
