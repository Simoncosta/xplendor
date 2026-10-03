<?php

declare(strict_types=1);

namespace App\Recommendations\Rules;

use App\Models\CompanyIntegration;
use App\Models\MetaCustomAudience;
use App\Recommendations\Contracts\RecommendationRule;
use App\Recommendations\Recommendation;
use App\Recommendations\RecommendationContext;
use App\Recommendations\RuleResult;
use App\Services\MetaAccountInsightsService;
use App\Services\MetaCustomAudiencesService;
use Carbon\CarbonImmutable;

/**
 * Regra 1 — "Público de clientes desatualizado" (só leitura).
 *
 * Uma LISTA DE CLIENTES na Meta (subtype CUSTOM, a única com time_content_updated)
 * que não é atualizada há mais de X dias deixa de fora os clientes recentes.
 * X por omissão 45, configurável entre 30 e 60. Prioridade sobe com os dias de
 * atraso e com o tamanho do público. Ação manual: atualizar no Gestor de Anúncios.
 *
 * Defensiva: se a leitura dos públicos falhou por permissão, devolve um AVISO
 * honesto ("sem permissão para ler os públicos") em vez de silêncio ou erro.
 */
class StaleCustomerAudienceRule implements RecommendationRule
{
    public const KEY = 'meta_stale_customer_audience';

    private const MIN_DAYS = 30;
    private const MAX_DAYS = 60;

    public function key(): string
    {
        return self::KEY;
    }

    public function verticals(): array
    {
        // Regra de marketing, serve qualquer ramo com a Meta ligada.
        return ['restaurant', 'automotive'];
    }

    public function defaultParams(): array
    {
        return ['max_days' => 45];
    }

    public function normalizeParams(array $params): array
    {
        $days = (int) ($params['max_days'] ?? 45);

        return ['max_days' => max(self::MIN_DAYS, min(self::MAX_DAYS, $days))];
    }

    public function evaluate(RecommendationContext $context, array $params): RuleResult
    {
        $integration = CompanyIntegration::where('company_id', $context->company->id)
            ->where('platform', 'meta')
            ->first();

        // Sem Meta ligada (ou sem conta) a regra não se aplica — sem aviso (o resto do
        // ecrã já trata desses estados).
        $accountId = MetaAccountInsightsService::normalizeAccountId($integration?->account_id);
        if (! $integration || $integration->status === 'revoked' || $accountId === null) {
            return RuleResult::none();
        }

        if ($integration->audiences_sync_status === MetaCustomAudiencesService::STATUS_NO_PERMISSION) {
            return new RuleResult([], [
                'code' => 'meta_audiences_no_permission',
                'message' => 'Sem permissão para ler os públicos da Meta. Esta verificação fica em pausa até a ligação à Meta ter essa permissão.',
            ]);
        }
        if ($integration->audiences_sync_status === MetaCustomAudiencesService::STATUS_FAILED) {
            return new RuleResult([], [
                'code' => 'meta_audiences_failed',
                'message' => 'Não foi possível ler os públicos da Meta na última sincronização. Volta a tentar-se no próximo ciclo.',
            ]);
        }

        $maxDays = $params['max_days'];
        $now = $context->now;

        $audiences = MetaCustomAudience::where('company_id', $context->company->id)
            ->where('account_id', $accountId)
            ->where('subtype', MetaCustomAudience::SUBTYPE_CUSTOMER_LIST)
            ->whereNotNull('time_content_updated')
            ->get();

        $recommendations = [];
        foreach ($audiences as $a) {
            $updated = CarbonImmutable::parse($a->time_content_updated);
            $days = (int) floor($updated->diffInDays($now));
            if ($days <= $maxDays) {
                continue;
            }

            $recommendations[] = new Recommendation(
                ruleKey: self::KEY,
                priority: $this->priority($days, $maxDays, $a->approximate_count_upper_bound),
                title: 'Público de clientes desatualizado',
                why: sprintf(
                    'O público «%s» (lista de clientes, %s) não é atualizado há %d dias. Os clientes que chegaram desde então não estão incluídos.',
                    $a->name ?? 'sem nome',
                    $this->sizePhrase($a->approximate_count_lower_bound, $a->approximate_count_upper_bound),
                    $days
                ),
                evidence: [
                    'audience_id' => $a->audience_id,
                    'audience_name' => $a->name,
                    'days_since_update' => $days,
                    'max_days' => $maxDays,
                    'last_content_update' => $updated->toDateString(),
                    'size_lower_bound' => $a->approximate_count_lower_bound,
                    'size_upper_bound' => $a->approximate_count_upper_bound,
                ],
                action: [
                    'label' => 'Atualizar no Gestor de Anúncios',
                    'url' => 'https://adsmanager.facebook.com/adsmanager/audiences?act=' . rawurlencode($accountId),
                ],
                generatedAt: $now,
            );
        }

        return new RuleResult($recommendations);
    }

    /**
     * 40 de base + até 40 pelo atraso (relativo ao limite) + até 20 pelo tamanho.
     * Ex.: 169 dias com limite 45 → 40 + 40 + 0 = 80 (Alta).
     */
    private function priority(int $days, int $maxDays, ?int $upperBound): int
    {
        $staleness = min(40, (int) round((($days - $maxDays) / $maxDays) * 40));

        $size = match (true) {
            $upperBound === null || $upperBound <= 1000 => 0,
            $upperBound <= 10000 => 8,
            $upperBound <= 100000 => 14,
            default => 20,
        };

        return min(100, 40 + max(0, $staleness) + $size);
    }

    /** "abaixo de 1 000 pessoas" / "entre 2 000 e 2 500 pessoas" / "tamanho desconhecido". */
    private function sizePhrase(?int $lower, ?int $upper): string
    {
        $n = fn (int $v) => number_format($v, 0, ',', ' ');

        if ($upper !== null && $upper <= 1000) {
            return 'abaixo de 1 000 pessoas';
        }
        if ($lower !== null && $lower > 0 && $upper !== null) {
            return sprintf('entre %s e %s pessoas', $n($lower), $n($upper));
        }

        return 'tamanho desconhecido';
    }
}
