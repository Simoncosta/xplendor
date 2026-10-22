<?php

namespace App\Jobs;

use App\Services\AlertService;
use App\Models\OcrInvoice;
use App\Services\InvoiceOcrService;
use App\Services\Ocr\OcrTemporaryFailure;
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
 *
 * Erros temporários da IA (sobrecarga, 5xx, limite de pedidos, sem resposta): a fatura continua
 * "a ler" e o job volta à fila com espera crescente (config ai.ocr_retry_backoff); só depois da
 * última tentativa fica em erro. A recusa e a resposta cortada vão para revisão manual (serviço).
 */
class ProcessInvoiceOcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 1 + as novas tentativas dos erros temporários (uma por espera de ai.ocr_retry_backoff). */
    public int $tries = 6;
    public int $timeout = 900; // até 2 tentativas da IA com várias páginas (OCR_HTTP_TIMEOUT cada)

    public function __construct(public int $companyId, public int $invoiceId) {}

    public function handle(InvoiceOcrService $ocr, AlertService $alerts): void
    {
        Log::info('[OCR Fatura] Job iniciado', ['company_id' => $this->companyId, 'invoice_id' => $this->invoiceId]);
        try {
            $ocr->process($this->invoiceId);
        } catch (OcrTemporaryFailure $e) {
            $backoff = array_values((array) config('ai.ocr_retry_backoff', [60, 120, 300, 600, 1200]));
            $attempt = max(1, $this->attempts());
            if ($attempt <= count($backoff) && $attempt < $this->tries) {
                $delay = (int) $backoff[$attempt - 1];
                Log::info('[OCR Fatura] Nova tentativa pela fila', ['invoice_id' => $this->invoiceId, 'tentativa' => $attempt, 'espera_s' => $delay]);
                $this->release($delay);

                return;
            }
            OcrInvoice::whereKey($this->invoiceId)->update(['status' => 'erro',
                'error_message' => mb_substr('O serviço de leitura esteve indisponível em todas as tentativas: ' . $e->getMessage(), 0, 500)]);
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Falha ao ler a fatura',
                message: 'O serviço de leitura esteve indisponível em todas as tentativas. Volte a pedir a leitura mais tarde.',
                severity: 'high',
                detailPath: '/restauracao/faturas',
            );

            return;
        } catch (\Throwable $e) {
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Falha ao ler a fatura',
                message: 'Não foi possível ler a fatura. Tente novamente ou envie uma imagem mais nítida.',
                severity: 'high',
                detailPath: '/restauracao/faturas',
            );

            return; // o estado 'erro' já foi gravado no serviço
        }

        $after = OcrInvoice::whereKey($this->invoiceId)->first(['status', 'error_message', 'qr_ok']);
        if ($after?->status === InvoiceOcrService::STATUS_NOT_OURS) {
            // Os dois NIF (o lido e o da empresa), como na mensagem guardada. Pelo QR, nem se leram as linhas.
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Fatura não é desta empresa',
                message: trim(($after->error_message ?: 'O adquirente da fatura não é esta empresa.') . ($after->qr_ok ? ' Não foi lida.' : '')),
                severity: 'medium',
                detailPath: "/restauracao/faturas/{$this->invoiceId}",
            );

            return;
        }
        // Parada antes da leitura (por exemplo, vários documentos no mesmo ficheiro).
        if ($after?->status === 'erro') {
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Fatura não lida',
                message: (string) $after->error_message,
                severity: 'medium',
                detailPath: "/restauracao/faturas/{$this->invoiceId}",
            );

            return;
        }

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Fatura lida',
            message: 'A fatura foi lida: verifique e valide os dados.',
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
