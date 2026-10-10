<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OcrInvoice;
use App\Models\PingwinDocumentWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * XPLENDOR — F2c: apagar faturas OCR (soft delete) e repor. Não escreve no PingWin.
 *
 * Regras:
 *  · a ser processada → não;
 *  · com documento lançado pela XPLENDOR que NÃO está anulado (rascunho/fechado, escrita em curso
 *    ou por confirmar) → não: "Anula primeiro o rascunho/documento no PingWin" (nunca se anula sozinho);
 *  · ligada (F3) a um documento lançado à mão no PingWin → sim: apaga a nossa cópia e desliga (o
 *    documento no PingWin fica);
 *  · o resto → sim (com confirmação na UI).
 * Apagar NÃO devolve a leitura ao teto mensal; o aprendido no mapa de artigos (F2b) mantém-se.
 * O ficheiro fica PURGE_AFTER_DAYS dias (repor só enquanto existir); a madrugada purga-o.
 */
class OcrInvoiceDeleteService
{
    public const PURGE_AFTER_DAYS = 30;
    public const LAUNCHED_REASON = 'Anula primeiro o rascunho/documento no PingWin.';

    public function __construct(private readonly OcrInvoiceLaunchService $launch) {}

    /** Motivo para NÃO apagar, ou null se pode. */
    public function blockReason(OcrInvoice $inv): ?string
    {
        if ($inv->status === 'processing') {
            return 'A fatura está a ser lida: espera que termine.';
        }
        $writes = PingwinDocumentWrite::where('ocr_invoice_id', $inv->id)->orderBy('id')->get(['id', 'action', 'status']);
        if ($writes->contains('status', PingwinDocumentWrite::PENDING)) {
            return 'Há uma escrita no PingWin em curso para esta fatura.';
        }
        if ($writes->contains('status', PingwinDocumentWrite::CONFIRM_ERROR)) {
            return 'Um lançamento no PingWin está por confirmar: revê-o primeiro. ' . self::LAUNCHED_REASON;
        }
        $draft = $this->launch->draftDoc($inv);
        if ($draft && $draft['docstatus_id'] !== OcrInvoiceLaunchService::VOIDED) {
            return "Lançada pela XPLENDOR ({$draft['document']}, {$draft['docstatus_label']}). " . self::LAUNCHED_REASON;
        }
        // Lançada (OK) e sem um "anular" OK depois — mesmo sem o documento no espelho.
        $launch = $writes->last(fn ($w) => $w->action === PingwinDocumentWrite::LAUNCH && $w->status === PingwinDocumentWrite::OK);
        if ($launch && ! $draft && ! $writes->contains(fn ($w) => $w->action === PingwinDocumentWrite::VOID && $w->status === PingwinDocumentWrite::OK && $w->id > $launch->id)) {
            return 'Lançada pela XPLENDOR no PingWin. ' . self::LAUNCHED_REASON;
        }

        return null;
    }

    /** Apaga (soft). Desliga a F3 (o documento no PingWin, se houver, fica). @throws \InvalidArgumentException */
    public function delete(OcrInvoice $inv, ?int $userId): void
    {
        if ($reason = $this->blockReason($inv)) {
            throw new \InvalidArgumentException($reason);
        }
        DB::transaction(function () use ($inv, $userId) {
            $inv->pingwinLinks()->delete();
            $inv->forceFill(['deleted_by' => $userId, 'link_status' => null, 'link_candidates' => null, 'duplicate_of_id' => null])->save();
            $inv->delete();
        });
        // As que a tinham como "original" voltam a ser verificadas (uma delas passa a ser a original).
        foreach (OcrInvoice::where('company_id', $inv->company_id)->where('duplicate_of_id', $inv->id)->get() as $dup) {
            app(OcrPingwinLinkService::class)->link($dup, false);
        }
    }

    /**
     * Apaga várias: as que não se podem apagar ficam de fora, com o motivo.
     * @return array{deleted: list<int>, skipped: list<array{id:int, number:?string, reason:string}>}
     */
    public function deleteMany(int $companyId, array $ids, ?int $userId): array
    {
        $out = ['deleted' => [], 'skipped' => []];
        $found = OcrInvoice::where('company_id', $companyId)->whereIn('id', $ids)->get()->keyBy('id');
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $inv = $found[$id] ?? null;
            if (! $inv) {
                $out['skipped'][] = ['id' => $id, 'number' => null, 'reason' => 'Fatura não encontrada.'];
                continue;
            }
            try {
                $this->delete($inv, $userId);
                $out['deleted'][] = $id;
            } catch (\InvalidArgumentException $e) {
                $out['skipped'][] = ['id' => $id, 'number' => $inv->number, 'reason' => $e->getMessage()];
            }
        }

        return $out;
    }

    /** Motivo para NÃO repor, ou null. */
    public function restoreBlockReason(OcrInvoice $inv): ?string
    {
        if (! $inv->trashed()) {
            return 'A fatura não está apagada.';
        }
        if ($inv->file_purged_at || ! $inv->image_path || ! Storage::disk($this->disk())->exists($inv->image_path)) {
            return 'O ficheiro já foi apagado (passaram ' . self::PURGE_AFTER_DAYS . ' dias): não se pode repor.';
        }
        if ($inv->file_sha256 && ($other = $this->sameFile($inv->company_id, $inv->file_sha256, $inv->id))) {
            return "O mesmo ficheiro está na fatura #{$other->id}: apaga-a primeiro, ou abre-a.";
        }

        return null;
    }

    /** Repõe e volta a ligar (F3 + artigos). @throws \InvalidArgumentException */
    public function restore(OcrInvoice $inv): OcrInvoice
    {
        if ($reason = $this->restoreBlockReason($inv)) {
            throw new \InvalidArgumentException($reason);
        }
        $inv->restore();
        $inv->forceFill(['deleted_by' => null])->save();
        try {
            app(OcrPingwinLinkService::class)->link($inv->fresh(), false);
            app(OcrLineArticleService::class)->linkInvoice($inv->fresh());
        } catch (\Throwable $e) {
            Log::warning('[OCR] repor: nova ligação falhou', ['invoice_id' => $inv->id, 'error' => $e->getMessage()]);
        }

        return $inv->fresh();
    }

    /** Outra fatura (não apagada) da empresa com o mesmo ficheiro. */
    public function sameFile(int $companyId, string $sha256, ?int $exceptId = null): ?OcrInvoice
    {
        return OcrInvoice::where('company_id', $companyId)->where('file_sha256', $sha256)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->orderBy('id')->first();
    }

    /** Madrugada: apaga do disco os ficheiros das faturas apagadas há mais de 30 dias. Devolve quantos. */
    public function purgeFiles(int $days = self::PURGE_AFTER_DAYS): int
    {
        $n = 0;
        OcrInvoice::onlyTrashed()->whereNull('file_purged_at')->where('deleted_at', '<', now()->subDays($days))
            ->orderBy('id')->each(function (OcrInvoice $inv) use (&$n) {
                try {
                    if ($inv->image_path) {
                        Storage::disk($this->disk())->delete($inv->image_path);
                    }
                    $inv->forceFill(['file_purged_at' => now()])->saveQuietly();
                    $n++;
                } catch (\Throwable $e) {
                    Log::warning('[OCR] purga do ficheiro falhou', ['invoice_id' => $inv->id, 'error' => $e->getMessage()]);
                }
            });

        return $n;
    }

    private function disk(): string
    {
        return (string) config('services.openai.ocr_disk', 'local');
    }
}
