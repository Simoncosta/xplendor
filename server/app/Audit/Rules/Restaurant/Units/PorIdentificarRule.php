<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Units;

use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\ProductUnitProfile;

/**
 * U8 · Artigo por identificar: a confiança está abaixo de 0,6 ou as evidências contradizem-se (a
 * ficha usa frações e a descrição diz cápsulas; o saco sem quantidade). O Guião mostra o que se sabe
 * e três saídas: dar mais informação, perguntar à IA, enviar ao administrador. € a confirmar: as
 * compras de 90 dias.
 */
final class PorIdentificarRule extends UnitRule
{
    public function code(): string
    {
        return 'restauracao.unidade_por_identificar';
    }

    public function title(): string
    {
        return 'Artigo por identificar';
    }

    public function description(): string
    {
        return 'Artigos sem evidências suficientes (ou contraditórias) para dizer em que unidade se compram, contam e usam.';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        foreach ($this->articles($scope) as $pid => $e) {
            if ($e['spend_90d_eur'] <= 0 && ! $e['recipes']) {
                continue;
            }
            $proposal = $this->proposal($scope, $e);
            $score = $this->confidence($scope, $e);
            if ($score >= ProductUnitProfile::MIN_CONFIDENCE && $proposal['identified']) {
                continue;
            }
            $reasons = array_merge($proposal['conflicts'], $e['confidence']['contradictions']);
            $question = $proposal['questions'][0]['text'] ?? null;
            yield $this->finding($e,
                problem: 'por identificar',
                seen: sprintf('Não há evidências que cheguem para dizer como %s se compra, conta e usa (confiança %s de 1).%s', $e['description'], Fmt::qty($score, 2),
                    $reasons ? ' ' . implode(' ', array_unique($reasons)) : ''),
                why: 'Sem saber a unidade, a XPLENDOR não calcula o custo nas fichas nem confere o stock deste artigo.',
                todo: 'Abra o Guião: responda' . ($question ? ' a «' . $question . '»' : ' às perguntas') . ', dê mais informação, peça uma sugestão ou envie ao administrador.',
                stake: $e['spend_90d_eur'] > 0 ? sprintf('A confirmar: %s comprados em 90 dias.', Fmt::eur($e['spend_90d_eur'])) : 'Sem compras em 90 dias: a relevância são as fichas.',
                evidence: ['facts' => self::facts($e, [['label' => 'Confiança', 'value' => Fmt::qty($score, 2)]]), 'table' => self::purchasesTable($e['purchases']),
                    'note' => $reasons ? implode(' ', array_unique($reasons)) : null],
                pendingCents: (int) round($e['spend_90d_eur'] * 100) ?: null, severity: 'media', signature: (string) round($score, 1),
            );
        }
    }
}
