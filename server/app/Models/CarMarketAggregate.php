<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarMarketAggregate extends Model
{
    protected $fillable = [
        'car_id',
        'vehicle_type',
        'method',
        'status',
        'confidence',
        'comparables_count',
        'outliers_removed',
        'median_price',
        'p25_price',
        'p75_price',
        'min_price',
        'max_price',
        'avg_price',
        'std_dev',
        'car_price_gross',
        'promo_price_gross',
        'search_url',
        'sources_breakdown',
        'funnel',
        'top_comparables',
        'fallback_used',
    ];

    protected $casts = [
        'median_price'      => 'decimal:2',
        'p25_price'         => 'decimal:2',
        'p75_price'         => 'decimal:2',
        'min_price'         => 'decimal:2',
        'max_price'         => 'decimal:2',
        'avg_price'         => 'decimal:2',
        'std_dev'           => 'decimal:2',
        'car_price_gross'   => 'decimal:2',
        'promo_price_gross' => 'decimal:2',
        'top_comparables'   => 'array',
        'sources_breakdown' => 'array',
        'funnel'            => 'array',
        'fallback_used'     => 'boolean',
        'comparables_count' => 'integer',
        'outliers_removed'  => 'integer',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /**
     * Effective price shown to buyers: promo price when active, otherwise gross price.
     * This is the price used in market comparison calculations.
     */
    public function effectivePrice(): ?float
    {
        // Regra ÚNICA do preço efetivo (App\Support\PricePosition), aplicada à cópia
        // do preço guardada no momento da análise. Antes usava a promo sempre que
        // existisse, mesmo acima do preço bruto.
        return \App\Support\PricePosition::effectivePrice($this->car_price_gross, $this->promo_price_gross);
    }

    /** Percentage difference between effective car price and market median. Positive = above market. */
    public function priceDifference(): ?float
    {
        $median = $this->median_price !== null ? (float) $this->median_price : null;

        return \App\Support\PricePosition::differencePct($this->effectivePrice(), $median);
    }

    /** Human-readable price position relative to market. */
    public function priceSignal(): ?string
    {
        // As MESMAS 4 faixas da fonte única (App\Support\PricePosition::band).
        return \App\Support\PricePosition::band($this->priceDifference());
    }
}
