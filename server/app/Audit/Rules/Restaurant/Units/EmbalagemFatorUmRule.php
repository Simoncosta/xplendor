<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Units;

use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Services\Restaurant\Units\UnitProposal;

/**
 * U1 · Embalagem na descrição e fator a 1: a descrição do fornecedor diz uma embalagem (1 kg, 24
 * unidades, caixa de 180) e a linha entrou com fator 1 no PingWin. € = o erro de quantidade em
 * unidades base × o custo (90 dias): o total × (N − 1) ÷ N. Não duplica a T6 (que só olha para
 * artigos com o fator já confirmado) nem a Regra 3 (o nome da própria unidade).
 */
final class EmbalagemFatorUmRule extends UnitRule
{
    public function code(): string
    {
        return 'restauracao.unidade_embalagem_fator_1';
    }

    public function title(): string
    {
        return 'Embalagem na descrição, fator 1 no PingWin';
    }

    public function description(): string
    {
        return 'A descrição do fornecedor diz quanto leva a embalagem, mas a linha entrou com fator 1.';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        // As linhas numa unidade global incoerente ficam na U9 (não se duplicam).
        $incoherent = UnidadeGlobalIncoerenteRule::incoherent($scope);
        foreach ($this->articles($scope) as $pid => $e) {
            $proposal = $this->proposal($scope, $e);
            foreach ($proposal['purchase_units'] as $i => $u) {
                $p = $e['purchases'][$i];
                if (isset($incoherent[$p['unit_id']])) {
                    continue;
                }
                if (! $u['factor'] || $u['factor'] <= 1 || $u['confirmed'] || $p['pingwin_factor'] === null || abs($p['pingwin_factor'] - 1) > 1e-6
                    || ! str_starts_with((string) $u['why'], 'Lido na descrição')) {
                    continue;
                }
                $n = $u['factor'];
                $eur = round($p['recent_eur'] * ($n - 1) / $n, 2);
                $base = $proposal['base'];
                yield $this->finding($e,
                    problem: sprintf('«%s» diz %s, entrou com fator 1', $p['packaging']['text'] ?? $u['unit_label'], $p['packaging']['label'] ?? ''),
                    seen: sprintf('%s fatura %s em «%s» com a descrição «%s». A descrição diz %s, mas a linha entrou no PingWin com fator 1: cada «%s» conta como %s.',
                        $p['supplier'] ?: 'O fornecedor', $e['description'], $u['unit_label'], $p['descriptions'][0] ?? '', mb_strtolower($p['packaging']['label'] ?? ''),
                        $u['unit_label'], UnitProposal::qty(1, $p['unit_family'] ?: 'un')),
                    why: sprintf('O stock fica com %s a menos por cada «%s», e o custo por unidade base sai %s vezes mais alto nas fichas.', UnitProposal::qty($n - 1, $base, $proposal['base_name']), $u['unit_label'], Fmt::qty($n, 0)),
                    todo: sprintf('Abra o Guião e confirme %s. A XPLENDOR passa a usar este fator; o PingWin só muda se aplicar.', $u['label']),
                    stake: sprintf('%s nos últimos 90 dias: o total destas compras (%s) × (%s − 1) ÷ %s.', Fmt::eur($eur), Fmt::eur($p['recent_eur']), Fmt::qty($n, 0), Fmt::qty($n, 0)),
                    evidence: ['facts' => self::facts($e, [['label' => 'Fator lido', 'value' => $u['label']], ['label' => 'Fator no PingWin', 'value' => '1']]),
                        'table' => self::purchasesTable([$p])],
                    impactCents: (int) round($eur * 100) ?: null, severity: self::severityFor($eur), key: ($p['nif'] ?: 'todos') . '|' . $p['unit_key'],
                    signature: (string) $n, refs: self::refsFor($e, [$p]),
                );
            }
        }
    }
}
