<?php

declare(strict_types=1);

namespace App\Services\Brand;

use App\Models\CreativeFormatRule;
use App\Models\EditorialPost;
use App\Models\SocialFollowerSnapshot;

/**
 * Que formato sugerir a uma publicação, e PORQUÊ, com a fonte:
 *  1. dados da própria conta (quando houver histórico de publicações com métricas) passam
 *     à frente de tudo;
 *  2. senão, a referência de mercado (creative_format_rules) para a faixa de seguidores;
 *  3. sem seguidores conhecidos ou sem regra para a rede: sem referência, e diz-se.
 * Esta decisão é do backend; a IA só a explica e escreve o criativo.
 */
class CreativeFormatAdvisor
{
    public const SOURCE_OWN_HISTORY = 'own_history';
    public const SOURCE_MARKET_REFERENCE = 'market_reference';
    public const SOURCE_NONE = 'none';

    public function recommend(int $companyId, string $channel): array
    {
        $own = $this->ownHistory($companyId, $channel);
        if ($own !== null) {
            return $own;
        }

        $snapshot = SocialFollowerSnapshot::where('company_id', $companyId)
            ->where('platform', $channel)
            ->orderByDesc('snapshot_date')
            ->first();

        $base = [
            'followers'      => $snapshot?->followers_count,
            'followers_date' => $snapshot?->snapshot_date?->toDateString(),
            'band'           => null,
            'ranked'         => [],
            'source_label'   => null,
            'source_url'     => null,
        ];

        if ($snapshot === null) {
            return ['source' => self::SOURCE_NONE, 'reason' => 'no_followers'] + $base;
        }

        $rules = CreativeFormatRule::forFollowers($channel, (int) $snapshot->followers_count)
            ->whereIn('format_key', EditorialPost::MEDIA_FORMATS[$channel] ?? [])
            ->orderBy('rank')
            ->orderByDesc('engagement_rate')
            ->get();

        if ($rules->isEmpty()) {
            return ['source' => self::SOURCE_NONE, 'reason' => 'no_rule'] + $base;
        }

        return [
            'source'       => self::SOURCE_MARKET_REFERENCE,
            'reason'       => 'band',
            'band'         => ['min' => $rules->first()->followers_min, 'max' => $rules->first()->followers_max],
            'ranked'       => $rules->map(fn (CreativeFormatRule $r) => [
                'format_key'      => $r->format_key,
                'label'           => EditorialPost::MEDIA_FORMAT_LABELS[$r->format_key] ?? $r->format_key,
                'engagement_rate' => $r->engagement_rate,
                'rank'            => $r->rank,
                'note'            => $r->note,
            ])->values()->all(),
            'source_label' => $rules->pluck('source_label')->unique()->implode('; '),
            'source_url'   => $rules->pluck('source_url')->filter()->first(),
        ] + array_intersect_key($base, array_flip(['followers', 'followers_date']));
    }

    /**
     * Histórico próprio por formato (alcance e interação das publicações da conta). Ainda
     * não existe: depende da leitura das métricas das publicações (permissões da Meta e
     * fases seguintes do plano Social). Quando existir, devolve source = own_history e
     * passa à frente da referência de mercado.
     */
    protected function ownHistory(int $companyId, string $channel): ?array
    {
        return null;
    }
}
