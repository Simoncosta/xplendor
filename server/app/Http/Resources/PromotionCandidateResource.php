<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Constants\StockThresholds;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha do Relatório A — candidata a promoção.
 *
 * NULL STATES SÃO O CASO NORMAL, não excepção:
 *   - viaturas sem market aggregate (motorhomes sem comparáveis)   → market: null
 *   - viaturas sem IPS calculado ainda (recentes)                  → ips: null
 *   - viaturas sem views ainda                                     → engagement.views=0
 *   - viaturas sem prioridade marcada                              → promotion: null
 *
 * O Resource emite estes campos COMO NULL, NÃO inventa zeros nem omite
 * silenciosamente — o consumidor (UI) decide como renderizar ("—",
 * chip neutro, badge "a calibrar", etc.).
 *
 * Campos derivados úteis ao consumidor (poupam recálculo no frontend):
 *   - `days_in_stock` (calculado em SQL via subquery ou inline na Repo,
 *     mas para simplicidade aqui calculamos com base em `car_created_at`)
 *   - `is_stale` (boolean — passou o threshold por vehicle_type? sec 17 de StockThresholds)
 *   - `effective_price` (promo se activo, senão gross)
 */
class PromotionCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'vehicle_type' => $this->vehicle_type,
            'status'       => $this->status,

            // Identificação — relações pré-carregadas pelo Repository.
            'brand'    => $this->whenLoaded('brand', fn() => [
                'id'   => $this->brand->id,
                'name' => $this->brand->name,
            ]),
            'model'    => $this->whenLoaded('model', fn() => [
                'id'   => $this->model->id,
                'name' => $this->model->name,
            ]),
            // Só motorhome tem categoria definida; carros têm `segment` em vez disso.
            'category' => $this->whenLoaded('category', fn() => $this->category ? [
                'id'   => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null),
            'segment'      => $this->segment,
            'engine_brand' => $this->engine_brand, // M2.2 — null em carros
            'version'      => $this->version,
            'public_version_name' => $this->public_version_name,
            'registration_year'   => $this->registration_year,
            'mileage_km'          => $this->mileage_km,

            // Imagem principal — relativa, frontend prefixa com REACT_APP_PUBLIC_URL.
            // null quando não há imagem (placeholder no UI).
            'thumbnail' => $this->whenLoaded('images', function () {
                $first = $this->images->first();
                return $first?->image;
            }, null),

            // Preços — `effective_price` poupa o cálculo no frontend.
            'price' => [
                'gross'           => $this->price_gross !== null ? (float) $this->price_gross : null,
                'promo'           => $this->promo_price_gross !== null ? (float) $this->promo_price_gross : null,
                'effective'       => $this->effectivePrice(),
                'has_promo'       => (bool) $this->has_promo_price,
                'hide_online'     => (bool) $this->hide_price_online,
            ],

            // Tempo no stand — `days_in_stock` calculado contra `car_created_at`
            // (data de entrada no stand) com fallback para `created_at` (data
            // do record). `is_stale` cruza com o threshold por vehicle_type.
            'days_in_stock' => $this->daysInStock(),
            'is_stale'      => $this->daysInStock() >= StockThresholds::ageThresholdFor($this->vehicle_type),

            // Engagement — subqueries puxam estas colunas como aliases.
            // Sempre números (0 é estado real, não null).
            'engagement' => [
                'views_count' => (int) ($this->views_count ?? 0),
                'leads_count' => (int) ($this->leads_count ?? 0),
            ],

            // Market aggregate — NULL quando não há comparáveis (motorhomes
            // sem matches em SV+CJ é o caso real e frequente).
            'market' => $this->whenLoaded('latestMarketAggregate', fn() => $this->latestMarketAggregate
                ? [
                    'status'            => $this->latestMarketAggregate->status,
                    'confidence'        => $this->latestMarketAggregate->confidence,
                    'comparables_count' => $this->latestMarketAggregate->comparables_count,
                    'median_price'      => $this->latestMarketAggregate->median_price !== null
                        ? (float) $this->latestMarketAggregate->median_price
                        : null,
                    // `priceSignal()` devolve null quando median ou preço efectivo
                    // estão em falta — coerente com a UI mostrar "—".
                    'price_signal'      => $this->latestMarketAggregate->priceSignal(),
                    'price_difference_percent' => $this->latestMarketAggregate->priceDifference(),
                ]
                : null
            ),

            // IPS — `score=null` + `classification='pending'` significa "a calibrar"
            // (sem sinais). A UI já tem helper `formatIpsBadge` para isso (sec 19).
            'ips' => $this->whenLoaded('latestSalePotentialScore', fn() => $this->latestSalePotentialScore
                ? [
                    'score'          => $this->latestSalePotentialScore->score, // int|null
                    'classification' => $this->latestSalePotentialScore->classification, // 'hot'|'warm'|'cold'|'pending'
                    'calculated_at'  => $this->latestSalePotentialScore->calculated_at?->toIso8601String(),
                ]
                : null
            ),

            // Prioridade actual (ACTIVA). Null = "não marcada".
            'promotion' => $this->whenLoaded('promotionPriority', fn() => $this->promotionPriority
                ? [
                    'id'         => $this->promotionPriority->id,
                    'marked_at'  => $this->promotionPriority->marked_at?->toIso8601String(),
                    'note'       => $this->promotionPriority->note,
                    'marked_by'  => $this->promotionPriority->markedBy ? [
                        'id'   => $this->promotionPriority->markedBy->id,
                        'name' => $this->promotionPriority->markedBy->name,
                    ] : null,
                ]
                : null
            ),
        ];
    }

    /**
     * Dias em stock — `car_created_at` é a data oficial (introdução manual),
     * com fallback para `created_at`. Devolve sempre int >= 0.
     */
    private function daysInStock(): int
    {
        $entry = $this->car_created_at ?? $this->created_at;
        if ($entry === null) {
            return 0;
        }
        return max(0, (int) $entry->diffInDays(now()));
    }

    /** Replica `CarMarketAggregate::effectivePrice` no contexto da viatura. */
    private function effectivePrice(): ?float
    {
        if ($this->promo_price_gross !== null) {
            return (float) $this->promo_price_gross;
        }
        return $this->price_gross !== null ? (float) $this->price_gross : null;
    }
}
