<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Money;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\OcrInvoice;
use App\Services\Restaurant\GuideReconciliationService;

/**
 * Fatia 5 · "Fatura refere guias não lançadas": uma fatura de fornecedor (OCR) refere guias de entrega
 * que não têm nenhum documento do mesmo fornecedor no PingWin (pelo número, pela data e pelas linhas).
 * € em jogo = o valor das linhas dessas guias, com IVA. Junta-se à C6 (guias sem fatura) no grupo
 * Dinheiro, no sentido inverso; não duplica: uma guia lançada no PingWin (a que a C6 vigia) fica
 * emparelhada e nunca conta como em falta.
 */
final class FaturaGuiasEmFaltaRule extends MoneyRule
{
    public function code(): string
    {
        return 'restauracao.dinheiro_fatura_guias_em_falta';
    }

    public function entityType(): string
    {
        return 'fatura_ocr';
    }

    public function title(): string
    {
        return 'Fatura refere guias não lançadas';
    }

    public function description(): string
    {
        return 'Uma fatura de fornecedor refere guias de entrega que não estão lançadas no PingWin.';
    }

    public function screens(): array
    {
        return ['faturas', 'fornecedores'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $svc = app(GuideReconciliationService::class);
        foreach (OcrInvoice::where('company_id', $companyId)->whereIn('status', ['por_validar', 'validada'])->whereNotNull('guide_refs')->orderBy('id')->get() as $inv) {
            $r = $svc->for($inv);
            if (! $r || $r['summary']['missing'] === 0) {
                continue;
            }
            $missing = array_values(array_filter($r['guides'], fn ($g) => $g['status'] === 'em_falta'));
            $eur = $r['summary']['missing_eur_cents'] / 100;
            $refs = implode(', ', array_map(fn ($g) => $g['ref'] . ($g['date'] ? ' (' . Fmt::date($g['date']) . ')' : ''), $missing));
            $number = trim((string) $inv->number);

            yield new AuditFindingData(
                entityType: 'fatura_ocr', entityId: (string) $inv->id, entityLabel: trim("{$number} · {$inv->supplier_name}"),
                title: Fmt::title($number ?: 'Fatura', sprintf('%d %s não %s', count($missing), count($missing) === 1 ? 'guia' : 'guias', count($missing) === 1 ? 'lançada' : 'lançadas')),
                why: 'A mercadoria destas guias foi faturada mas não entrou no PingWin: o stock e o custo das fichas ficam por baixo, e a conta corrente do fornecedor não bate com a fatura.',
                suggestion: 'Abra a conferência com as guias na validação da fatura e lance as guias em falta (o lançamento pede sempre a sua confirmação).',
                evidence: [
                    'facts' => [
                        ['label' => 'Fornecedor', 'value' => (string) $inv->supplier_name],
                        ['label' => 'Guias referidas', 'value' => (string) $r['summary']['referred']],
                        ['label' => 'Lançadas no PingWin', 'value' => (string) $r['summary']['launched']],
                        ['label' => 'Em falta', 'value' => $refs],
                    ],
                    'table' => [
                        'title' => 'As guias em falta',
                        'columns' => [['key' => 'ref', 'label' => 'Guia'], ['key' => 'date', 'label' => 'Data', 'format' => 'date'], ['key' => 'eur', 'label' => 'Valor com IVA', 'align' => 'end', 'format' => 'eur']],
                        'rows' => array_map(fn ($g) => ['ref' => $g['ref'], 'date' => $g['date'], 'eur' => $g['eur_cents'] / 100], $missing),
                    ],
                ],
                signature: implode('|', array_column($missing, 'ref')),
                impactCents: (int) $r['summary']['missing_eur_cents'], severity: self::severityFor($eur),
                fixUrl: '/restauracao/faturas/' . $inv->id . '#guias', fixLabel: 'Abrir a conferência com as guias',
                refs: [['fatura_ocr', (string) $inv->id]],
                seen: sprintf('A fatura %s de %s refere %d guias; %d %s no PingWin: %s.', $number, $inv->supplier_name, $r['summary']['referred'], count($missing),
                    count($missing) === 1 ? 'não está lançada' : 'não estão lançadas', $refs),
                stake: sprintf('%s: o valor das linhas destas guias na fatura, com IVA.', Fmt::eur($eur)),
            );
        }
    }
}
