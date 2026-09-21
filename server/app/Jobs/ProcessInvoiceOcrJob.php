<?php

namespace App\Jobs;

use App\Services\AlertService;
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
    public int $timeout = 180;

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
