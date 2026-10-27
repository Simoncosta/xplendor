<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessInvoiceOcrJob;
use App\Models\OcrInvoice;
use App\Services\Financing\Antivirus\ClamdUnavailable;
use App\Services\Financing\Antivirus\VirusScanner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A entrada de uma fatura nas Faturas (OCR), a mesma para o carregamento manual e para o anexo
 * importado da caixa de correio (E-mail 7): primeiro o ClamAV (vírus, ou antivírus em baixo ou por
 * configurar: recusa, com o motivo); o mesmo ficheiro já carregado (e não apagado) é recusado
 * antes da IA e do teto; depois o teto mensal; o ficheiro fica privado no disco das faturas; a leitura
 * (QR primeiro, depois a IA, o fornecedor pelo NIF) corre na fila, em ProcessInvoiceOcrJob.
 */
class OcrInvoiceIntake
{
    public const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const MAX_BYTES = 12 * 1024 * 1024;

    public function __construct(private readonly OcrInvoiceDeleteService $deletes, private readonly VirusScanner $scanner) {}

    /**
     * @param  array{origin?: string, origin_mailbox_id?: ?int, origin_mailbox_email?: ?string, origin_message_id?: ?string}  $origin
     * @return array{status: 'created'|'duplicate'|'cap'|'refused', invoice?: OcrInvoice, cap?: int, error?: string, code?: int}
     */
    public function accept(int $companyId, string $bytes, string $mime, array $origin = [], string $name = ''): array
    {
        // O antivírus antes de tudo (num ficheiro temporário, apagado a seguir).
        if ($refused = $this->scan($companyId, $bytes, $name)) {
            return $refused;
        }
        $sha256 = hash('sha256', $bytes);
        if ($same = $this->deletes->sameFile($companyId, $sha256)) {
            return ['status' => 'duplicate', 'invoice' => $same];
        }
        if ($this->usedThisMonth($companyId) >= $this->monthlyCap()) {
            return ['status' => 'cap', 'cap' => $this->monthlyCap()];
        }

        $path = "ocr-invoices/{$companyId}/" . Str::random(40) . '.' . (self::MIMES[strtolower($mime)] ?? 'bin');
        Storage::disk($this->disk())->put($path, $bytes);

        $invoice = OcrInvoice::create([
            'company_id' => $companyId,
            'image_path' => $path,
            'image_size_bytes' => strlen($bytes),
            'image_mime' => $mime,
            'file_sha256' => $sha256,
            'status' => 'processing',
            'synced_to_pingwin' => false,
        ] + array_intersect_key($origin, array_flip(['origin', 'origin_mailbox_id', 'origin_mailbox_email', 'origin_message_id'])));
        ProcessInvoiceOcrJob::dispatch($companyId, $invoice->id);

        return ['status' => 'created', 'invoice' => $invoice];
    }

    /** null se está limpo; senão, a recusa com o motivo (422 vírus, 503 antivírus indisponível). */
    private function scan(int $companyId, string $bytes, string $name): ?array
    {
        $path = 'antivirus-scan/' . Str::uuid();
        Storage::disk('local')->put($path, $bytes);
        try {
            $verdict = $this->scanner->scan('local', $path);
        } catch (ClamdUnavailable) {
            return ['status' => 'refused', 'error' => 'O antivírus não está disponível neste momento, por isso a fatura não foi carregada. Tente daqui a pouco.', 'code' => 503];
        } catch (Throwable $e) {
            Log::warning('Faturas: a análise antivírus falhou.', ['company_id' => $companyId, 'error' => $e->getMessage()]);

            return ['status' => 'refused', 'error' => 'Não foi possível analisar o ficheiro com o antivírus, por isso a fatura não foi carregada.', 'code' => 503];
        } finally {
            Storage::disk('local')->delete($path);
        }
        if ($verdict !== VirusScanner::CLEAN) {
            Log::warning('Faturas: ficheiro com vírus recusado.', ['company_id' => $companyId, 'signature' => $this->scanner->lastSignature(), 'name' => $name]);

            return ['status' => 'refused', 'error' => 'O antivírus encontrou um vírus neste ficheiro. A fatura não foi carregada.', 'code' => 422];
        }

        return null;
    }

    public function monthlyCap(): int
    {
        return (int) config('services.openai.ocr_monthly_cap', 200);
    }

    /** Leituras do mês (as apagadas CONTAM: apagar não devolve a leitura ao teto). */
    public function usedThisMonth(int $companyId): int
    {
        return OcrInvoice::withTrashed()->where('company_id', $companyId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    private function disk(): string
    {
        return (string) config('services.openai.ocr_disk', 'local');
    }
}
