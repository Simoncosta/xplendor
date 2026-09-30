<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarMarketAggregateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'status'             => $this->status,
            'confidence'         => $this->confidence,
            'comparables_count'  => $this->comparables_count,
            // FASE 1b — a UI escolhe a vista (autocaravana vs carro) por
            // `method`; `vehicle_type` é o segundo sinal para o caso
            // guard-failed (motorhome sem tipologia mapeável, em que o
            // aggregate nasce failed com method ainda null).
            'vehicle_type'       => $this->vehicle_type,
            // FASE 1 — motor de similaridade de autocaravanas. `method` diz à
            // UI QUE motor produziu o aggregate ('motorhome_similarity_v1' =
            // comparação por tipologia+ano, cross-marca; null = cascata
            // clássica dos carros). p25/p75 só vêm preenchidos com n>=4 —
            // NUNCA são min/max disfarçados. `funnel` explica um resultado
            // vazio; `outliers_removed` alimenta o copy "(M excluídos por
            // preço atípico)".
            'method'             => $this->method,
            'outliers_removed'   => (int) ($this->outliers_removed ?? 0),
            'funnel'             => $this->funnel,
            'prices'             => [
                'median' => $this->median_price !== null ? (float) $this->median_price : null,
                'p25'    => $this->p25_price    !== null ? (float) $this->p25_price    : null,
                'p75'    => $this->p75_price    !== null ? (float) $this->p75_price    : null,
                'min'    => $this->min_price    !== null ? (float) $this->min_price    : null,
                'max'    => $this->max_price    !== null ? (float) $this->max_price    : null,
                'avg'    => $this->avg_price    !== null ? (float) $this->avg_price    : null,
            ],
            'comparison' => [
                'car_price'          => $this->effectivePrice(),
                'difference_percent' => $this->priceDifference(),
                'signal'             => $this->priceSignal(),
            ] + ($this->promo_price_gross !== null
                ? ['car_price_gross' => (float) $this->car_price_gross]
                : []),
            'top_comparables' => $this->top_comparables ?? [],
            'fallback_used'   => (bool) $this->fallback_used,
            'search_url'      => $this->search_url,
            // MS2.e — contagens por fonte sobre o pool POS-dedup que alimentou
            // a mediana. Optional no payload (snapshots históricos não têm).
            'sources_breakdown' => $this->sources_breakdown,
            // MS1.c — flag derivado do car associado para o UI escolher a mensagem
            // accionável quando o aggregate está vazio. Carregado via
            // loadMissing('car:id,hide_price_online') no controller. Defensivo:
            // se o car não estiver carregado por algum caminho, devolve false.
            'hide_price_online' => (bool) ($this->car?->hide_price_online ?? false),
            'created_at'      => $this->created_at->toIso8601String(),
            'updated_at'      => $this->updated_at->toIso8601String(),
        ];
    }
}
