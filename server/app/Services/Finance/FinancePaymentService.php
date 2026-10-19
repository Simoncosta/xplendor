<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\FinancePayment;
use App\Models\PingwinDocumentConfig;
use App\Models\PingwinSupplierCcDocument;
use App\Models\PingwinSupplierDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Finanças: os pagamentos registados na XPLENDOR enquanto não há nota de liquidação no
 * PingWin ("Registar pagamento" e "Regularizar" vários com um só pagamento). Nada se escreve no
 * PingWin. Desfazer fica no histórico. O valor de cada documento é o por pagar da conta corrente (sem
 * ela, o total do documento).
 */
class FinancePaymentService
{
    /**
     * @param  list<string>  $docheaderIds
     * @return Collection<int, FinancePayment>
     */
    public function register(int $companyId, array $docheaderIds, string $paidOn, string $method, ?string $reference, ?int $userId): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $docheaderIds))));
        if ($ids === []) {
            throw ValidationException::withMessages(['docheader_ids' => ['Escolha pelo menos um documento.']]);
        }
        if (! array_key_exists($method, FinancePayment::METHODS)) {
            throw ValidationException::withMessages(['method' => ['Escolha a forma de pagamento.']]);
        }
        $docs = PingwinSupplierDocument::where('company_id', $companyId)->whereIn('docheader_id', $ids)->get()->keyBy('docheader_id');
        if ($docs->count() !== count($ids)) {
            throw ValidationException::withMessages(['docheader_ids' => ['Documento não encontrado.']]);
        }
        $cc = PingwinSupplierCcDocument::where('company_id', $companyId)->whereIn('docheader_id', $ids)->get()->keyBy('docheader_id');
        $auto = PingwinDocumentConfig::where('company_id', $companyId)->where('settled', true)->pluck('external_id')->map(fn ($v) => (string) $v)->flip();
        $already = FinancePayment::where('company_id', $companyId)->whereIn('docheader_id', $ids)->whereNull('undone_at')->pluck('docheader_id')->flip();
        foreach ($ids as $id) {
            $c = $cc[$id] ?? null;
            $why = match (true) {
                isset($already[$id]) => 'já tem um pagamento registado na XPLENDOR',
                $c !== null && isset($auto[(string) $c->docconfig_id]) => 'é uma fatura-recibo, paga no ato',
                $c !== null && $c->topay_cents === 0 => 'já está pago no PingWin',
                $c !== null && $c->ca_signal === -1 => 'é um crédito, não um pagamento',
                default => null,
            };
            if ($why) {
                throw ValidationException::withMessages(['docheader_ids' => ["{$docs[$id]->document}: {$why}."]]);
            }
        }
        $batch = count($ids) > 1 ? (string) Str::uuid() : null;

        return DB::transaction(fn () => collect($ids)->map(fn ($id) => FinancePayment::create([
            'company_id' => $companyId, 'batch' => $batch, 'docheader_id' => $id, 'entity_pingwin_id' => $docs[$id]->entity_pingwin_id,
            'amount_cents' => isset($cc[$id]) ? (int) $cc[$id]->topay_cents : (int) $docs[$id]->total_cents,
            'paid_on' => $paidOn, 'method' => $method, 'reference' => $reference ? mb_substr(trim($reference), 0, 120) : null, 'created_by' => $userId,
        ])));
    }

    public function undo(FinancePayment $p, ?int $userId): void
    {
        if ($p->undone_at) {
            throw ValidationException::withMessages(['payment' => ['Este pagamento já foi desfeito.']]);
        }
        $p->update(['undone_at' => now(), 'undone_by' => $userId]);
    }

    /** O histórico dos pagamentos registados de um documento (também os desfeitos). */
    public function history(int $companyId, string $docheaderId): array
    {
        return FinancePayment::where('company_id', $companyId)->where('docheader_id', $docheaderId)->with('creator:id,name')->orderByDesc('id')->get()
            ->map(fn ($p) => ['id' => $p->id, 'paid_on' => $p->paid_on->toDateString(), 'method' => $p->method, 'method_label' => FinancePayment::METHODS[$p->method] ?? $p->method,
                'reference' => $p->reference, 'amount_cents' => $p->amount_cents, 'by' => $p->creator?->name, 'at' => $p->created_at?->toIso8601String(),
                'undone_at' => $p->undone_at?->toIso8601String()])->all();
    }

    /**
     * "Regularizar": por fornecedor, os documentos por pagar e vencidos da conta corrente (de todos os
     * meses), sem os que já têm um pagamento registado na XPLENDOR.
     */
    public function payables(int $companyId, ?string $today = null): array
    {
        $today ??= now('Europe/Lisbon')->toDateString();
        $auto = PingwinDocumentConfig::where('company_id', $companyId)->where('settled', true)->pluck('external_id')->map(fn ($v) => (string) $v)->all();
        $paid = FinancePayment::where('company_id', $companyId)->whereNull('undone_at')->pluck('docheader_id')->all();
        $rows = PingwinSupplierCcDocument::where('company_id', $companyId)->where('ca_signal', 1)->where('topay_cents', '>', 0)
            ->whereNotIn('docconfig_id', array_merge($auto, (array) config('finance_month.payment_docconfigs')))->whereNotIn('docheader_id', $paid)
            ->orderBy('due_date')->get();
        $names = DB::table('suppliers')->where('company_id', $companyId)->pluck('name', 'id');

        return $rows->groupBy('supplier_id')->map(fn ($docs, $sid) => [
            'supplier_id' => (int) $sid, 'supplier' => $names[$sid] ?? null, 'entity_pingwin_id' => $docs->first()->entity_pingwin_id,
            'total_cents' => (int) $docs->sum('topay_cents'),
            'overdue_cents' => (int) $docs->filter(fn ($d) => $d->due_date && $d->due_date->toDateString() < $today)->sum('topay_cents'),
            'documents' => $docs->map(fn ($d) => ['docheader_id' => $d->docheader_id, 'document' => $d->document, 'doc_date' => $d->doc_date?->toDateString(),
                'due_date' => $d->due_date?->toDateString(), 'open_cents' => (int) $d->topay_cents,
                'overdue' => $d->due_date !== null && $d->due_date->toDateString() < $today])->values()->all(),
        ])->sortByDesc('overdue_cents')->values()->all();
    }
}
