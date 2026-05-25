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
            'prices'             => [
                'median' => $this->median_price !== null ? (float) $this->median_price : null,
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
            'created_at'      => $this->created_at->toIso8601String(),
            'updated_at'      => $this->updated_at->toIso8601String(),
        ];
    }
}
