<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Money;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\PingwinSupplierDocument;
use Illuminate\Support\Facades\DB;

/**
 * C6 · Guias de fornecedor sem fatura: guias (1203, 1201) com mais de 30 dias que nunca passaram a
 * fatura. Convertida = outra fatura aponta para ela (o documento de origem nas linhas, ou o número na
 * referência), ou o estado no PingWin diz que foi convertida ou faturada. € em jogo = o total da guia
 * (a mercadoria entrou e ainda não há fatura: o custo e a conta corrente ficam por baixo). O caso
 * inverso (fatura sem guia) fica para a Fatia 5.
 */
final class GuiaSemFaturaRule extends MoneyRule
{
    public function code(): string
    {
        return 'restauracao.dinheiro_guia_sem_fatura';
    }

    public function entityType(): string
    {
        return 'documento';
    }

    public function title(): string
    {
        return 'Guias de fornecedor sem fatura';
    }

    public function description(): string
    {
        return 'Guias de remessa de fornecedor com mais de 30 dias que nunca passaram a fatura.';
    }

    public function screens(): array
    {
        return ['faturas', 'fornecedores'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = $this->data($scope);
        $types = array_map('strval', (array) self::cfg('guide_docconfigs', ['1203', '1201']));
        $limit = $data->daysAgo((int) self::cfg('guide_days', 30));
        $guides = PingwinSupplierDocument::where('company_id', $companyId)->whereIn('docconfig_id', $types)
            ->where('docstatus_id', '!=', PingwinSupplierDocument::STATUS_ANULADO)->where('doc_date', '<=', $limit)->where('doc_date', '>=', $data->daysAgo(365))
            ->orderBy('doc_date')->get(['docheader_id', 'document', 'doctype', 'doc_date', 'entity_name', 'entity_pingwin_id', 'total_cents', 'docstatus_description']);
        if ($guides->isEmpty()) {
            return;
        }
        $converted = self::convertedIds($companyId, $guides->all());
        foreach ($guides as $g) {
            if (isset($converted[(string) $g->docheader_id]) || preg_match('/convert|fatur/i', (string) $g->docstatus_description)) {
                continue;
            }
            $date = substr((string) $g->doc_date, 0, 10);
            $age = (int) round((strtotime($data->today()) - strtotime($date)) / 86400);
            $doc = Fmt::doc($g->document);
            $total = ((int) $g->total_cents) / 100;

            yield new AuditFindingData(
                entityType: 'documento', entityId: (string) $g->docheader_id, entityLabel: (string) $g->document,
                title: Fmt::title($doc, "guia sem fatura há {$age} dias"),
                why: 'A mercadoria entrou, mas a fatura não chegou (ou não foi lançada a partir da guia): o custo das fichas e a conta corrente ficam por baixo, e a fatura pode chegar em duplicado mais tarde.',
                suggestion: sprintf('Peça a fatura a %s e lance-a a partir da guia no PingWin. Se a fatura já foi lançada à parte, ligue-a à guia.', $g->entity_name ?: 'o fornecedor'),
                evidence: ['facts' => [
                    ['label' => 'Documento', 'value' => trim("{$g->doctype} {$doc}")],
                    ['label' => 'Data', 'value' => Fmt::date($date)],
                    ['label' => 'Fornecedor', 'value' => (string) $g->entity_name],
                    ['label' => 'Estado no PingWin', 'value' => (string) ($g->docstatus_description ?: '—')],
                    ['label' => 'Total', 'value' => $total, 'format' => 'eur'],
                ]],
                signature: (string) $g->docstatus_description,
                impactCents: (int) $g->total_cents, severity: self::severityFor($total),
                fixUrl: self::documentUrl((string) $g->docheader_id), fixLabel: 'Abrir a guia',
                refs: array_values(array_filter([['documento', (string) $g->docheader_id], $g->entity_pingwin_id ? ['fornecedor', (string) $g->entity_pingwin_id] : null])),
                seen: sprintf('A guia %s de %s, de %s, tem %d dias e nenhuma fatura a aponta.', $doc, $g->entity_name, Fmt::date($date), $age),
                stake: sprintf('%s: o total da guia.', Fmt::eur($total)),
            );
        }
    }

    /** As guias convertidas: alguma linha de outro documento aponta para a guia, ou a referência tem o número dela. */
    public static function convertedIds(int $companyId, array $guides): array
    {
        $ids = array_map(fn ($g) => (string) $g->docheader_id, $guides);
        $out = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $rows = DB::table('pingwin_supplier_document_lines')->where('company_id', $companyId)->whereNotIn('docheader_id', $chunk)
                ->where(function ($q) use ($chunk) {
                    foreach ($chunk as $id) {
                        $q->orWhere('raw', 'like', '%"src_docheader_id":"' . $id . '"%');
                    }
                })->pluck('raw');
            foreach ($rows as $raw) {
                $src = (string) ((json_decode((string) $raw, true) ?: [])['src_docheader_id'] ?? '');
                if ($src !== '') {
                    $out[$src] = true;
                }
            }
        }
        $numbers = [];
        foreach ($guides as $g) {
            $numbers[mb_strtoupper(Fmt::doc($g->document))] = (string) $g->docheader_id;
            $numbers[mb_strtoupper(trim((string) $g->document))] = (string) $g->docheader_id;
        }
        foreach (PingwinSupplierDocument::where('company_id', $companyId)->whereNotIn('docheader_id', $ids)->where('docreference_number', '!=', '')
            ->whereNotNull('docreference_number')->pluck('docreference_number') as $ref) {
            $k = mb_strtoupper(trim((string) $ref));
            if (isset($numbers[$k])) {
                $out[$numbers[$k]] = true;
            }
        }

        return $out;
    }
}
