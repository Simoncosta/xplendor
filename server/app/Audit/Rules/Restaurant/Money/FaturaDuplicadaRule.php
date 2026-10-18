<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Money;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\PingwinSupplierDocument;
use App\Services\Restaurant\RecipeCostService;
use App\Services\SupplierArticleMapService;
use Illuminate\Support\Facades\DB;

/**
 * C5 · Faturas de fornecedor em duplicado: duas faturas (não anuladas) do mesmo fornecedor com
 *  · o mesmo total (±0,01 €) em 15 dias e números diferentes; ou
 *  · o mesmo documento de referência (a fatura do fornecedor) duas vezes; ou
 *  · as mesmas linhas (artigo, quantidade, preço) em 15 dias.
 * O mesmo total em fornecedores diferentes não é duplicado. € em jogo = o total do documento
 * suspeito (o mais recente). Ação: abrir os dois documentos lado a lado. Só lê.
 */
final class FaturaDuplicadaRule extends MoneyRule
{
    public function code(): string
    {
        return 'restauracao.dinheiro_fatura_duplicada';
    }

    public function entityType(): string
    {
        return 'documento';
    }

    public function title(): string
    {
        return 'Faturas de fornecedor em duplicado';
    }

    public function description(): string
    {
        return 'Duas faturas do mesmo fornecedor com o mesmo total, a mesma referência ou as mesmas linhas: a mesma compra pode ter sido lançada (e paga) duas vezes.';
    }

    public function screens(): array
    {
        return ['faturas', 'fornecedores'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = $this->data($scope);
        $days = (int) self::cfg('duplicate_days', 15);
        $docs = PingwinSupplierDocument::where('company_id', $companyId)->whereIn('docconfig_id', RecipeCostService::PURCHASE_DOCCONFIGS)
            ->where('docstatus_id', '!=', PingwinSupplierDocument::STATUS_ANULADO)->where('doc_date', '>=', $data->daysAgo(365))
            ->where('total_cents', '>', (int) round((float) self::cfg('duplicate_min_eur', 1) * 100))
            ->orderBy('doc_date')->orderBy('id')
            ->get(['id', 'docheader_id', 'document', 'doctype', 'doc_date', 'entity_name', 'entity_pingwin_id', 'tax_number', 'total_cents', 'docreference_number', 'docstatus_description']);
        $lines = [];
        foreach (DB::table('pingwin_supplier_document_lines')->where('company_id', $companyId)->whereIn('docheader_id', $docs->pluck('docheader_id'))
            ->orderBy('docheader_id')->orderBy('line_number')->get(['docheader_id', 'product_pingwin_id', 'qnt', 'price']) as $l) {
            $lines[(string) $l->docheader_id][] = sprintf('%s|%.3f|%.4f', $l->product_pingwin_id, $l->qnt, $l->price);
        }
        $bySupplier = [];
        foreach ($docs as $d) {
            $sk = SupplierArticleMapService::nif($d->tax_number) ?: (string) $d->entity_pingwin_id;
            if ($sk !== '') {
                $bySupplier[$sk][] = $d;
            }
        }
        $flagged = [];
        foreach ($bySupplier as $list) {
            // O mesmo total três ou mais vezes no ano é uma avença ou uma encomenda fixa, não um duplicado
            // (a não ser no mesmo dia ou no dia seguinte).
            $repeats = array_count_values(array_map(fn ($d) => (int) $d->total_cents, $list));
            $n = count($list);
            for ($j = 1; $j < $n; $j++) {
                $b = $list[$j];
                for ($i = $j - 1; $i >= 0; $i--) {
                    $a = $list[$i];
                    $gap = (int) round((strtotime(substr((string) $b->doc_date, 0, 10)) - strtotime(substr((string) $a->doc_date, 0, 10))) / 86400);
                    $refA = self::ref($a->docreference_number);
                    $refB = self::ref($b->docreference_number);
                    $sameRef = $refA !== '' && $refA === $refB;
                    // As duas com a referência do fornecedor, e diferentes: são duas faturas do fornecedor.
                    if ($refA !== '' && $refB !== '' && ! $sameRef) {
                        continue;
                    }
                    if ($gap > $days && ! $sameRef) {
                        continue;
                    }
                    if ($a->document === $b->document) {
                        continue;
                    }
                    $recurring = ($repeats[(int) $b->total_cents] ?? 0) >= 3 && $gap > 1;
                    $why = match (true) {
                        $sameRef => 'referencia',
                        $recurring => null,
                        abs((int) $a->total_cents - (int) $b->total_cents) <= 1 => 'total',
                        isset($lines[(string) $a->docheader_id], $lines[(string) $b->docheader_id]) && self::sameLines($lines[(string) $a->docheader_id], $lines[(string) $b->docheader_id]) => 'linhas',
                        default => null,
                    };
                    if ($why === null || isset($flagged[(string) $b->docheader_id])) {
                        continue;
                    }
                    $flagged[(string) $b->docheader_id] = true;
                    yield $this->finding($a, $b, $why, $gap);
                    break;
                }
            }
        }
    }

    private static function ref(?string $r): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', (string) $r) ?? '');
    }

    /** As mesmas linhas (artigo, quantidade, preço), pela mesma ordem ou não. */
    public static function sameLines(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a && $a === $b;
    }

    private function finding(PingwinSupplierDocument $a, PingwinSupplierDocument $b, string $why, int $gap): AuditFindingData
    {
        $da = Fmt::doc($a->document);
        $db = Fmt::doc($b->document);
        $total = ((int) $b->total_cents) / 100;
        $apart = $gap === 0 ? 'no mesmo dia' : sprintf('com %d %s de diferença', $gap, $gap === 1 ? 'dia' : 'dias');
        $reason = match ($why) {
            'referencia' => sprintf('a mesma referência do fornecedor (%s)', $a->docreference_number),
            'total' => sprintf('o mesmo total (%s) %s', Fmt::cents((int) $b->total_cents), $apart),
            default => sprintf('as mesmas linhas (artigo, quantidade e preço) %s', $apart),
        };

        return new AuditFindingData(
            entityType: 'documento', entityId: (string) $b->docheader_id, entityLabel: (string) $b->document,
            title: Fmt::title($db, sprintf('possível duplicado de %s', $da)),
            why: 'Uma fatura lançada duas vezes entra duas vezes no custo, no stock e na conta corrente, e pode ser paga duas vezes.',
            suggestion: sprintf('Abra os dois documentos lado a lado. Se for a mesma compra, anule %s no PingWin e avise quem paga; se forem compras diferentes, ignore com o motivo.', $db),
            evidence: [
                'facts' => [
                    ['label' => 'Fornecedor', 'value' => (string) $b->entity_name],
                    ['label' => 'Porquê', 'value' => ucfirst($reason)],
                ],
                'table' => [
                    'title' => 'Os dois documentos',
                    'columns' => [['key' => 'document', 'label' => 'Documento'], ['key' => 'date', 'label' => 'Data', 'format' => 'date'],
                        ['key' => 'reference', 'label' => 'Referência'], ['key' => 'state', 'label' => 'Estado'], ['key' => 'total', 'label' => 'Total', 'align' => 'end', 'format' => 'eur']],
                    'rows' => array_map(fn ($d) => ['document' => Fmt::doc($d->document), 'date' => substr((string) $d->doc_date, 0, 10), 'reference' => (string) ($d->docreference_number ?: '—'),
                        'state' => (string) ($d->docstatus_description ?: '—'), 'total' => ((int) $d->total_cents) / 100], [$a, $b]),
                ],
            ],
            evidenceKey: (string) $a->docheader_id, signature: $why,
            impactCents: (int) $b->total_cents, severity: self::severityFor($total),
            fixUrl: self::documentUrl((string) $b->docheader_id), fixLabel: 'Ver lado a lado',
            fixAction: ['type' => 'documentos_lado_a_lado', 'documents' => [(string) $a->docheader_id, (string) $b->docheader_id]],
            refs: array_values(array_filter([['documento', (string) $b->docheader_id], ['documento', (string) $a->docheader_id],
                $b->entity_pingwin_id ? ['fornecedor', (string) $b->entity_pingwin_id] : null])),
            seen: sprintf('Há duas faturas de %s, %s de %s e %s de %s, com %s.', $b->entity_name, $da, Fmt::date(substr((string) $a->doc_date, 0, 10)), $db, Fmt::date(substr((string) $b->doc_date, 0, 10)), $reason),
            stake: sprintf('%s: o total do documento suspeito (%s).', Fmt::eur($total), $db),
        );
    }
}
