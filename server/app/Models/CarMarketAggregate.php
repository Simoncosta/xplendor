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
        'status',
        'confidence',
        'comparables_count',
        'median_price',
        'min_price',
        'max_price',
        'avg_price',
        'std_dev',
        'car_price_gross',
        'promo_price_gross',
        'top_comparables',
        'fallback_used',
    ];

    protected $casts = [
        'median_price'      => 'decimal:2',
        'min_price'         => 'decimal:2',
        'max_price'         => 'decimal:2',
        'avg_price'         => 'decimal:2',
        'std_dev'           => 'decimal:2',
        'car_price_gross'   => 'decimal:2',
        'promo_price_gross' => 'decimal:2',
        'top_comparables'   => 'array',
        'fallback_used'     => 'boolean',
        'comparables_count' => 'integer',
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
        if ($this->promo_price_gross !== null) {
            return (float) $this->promo_price_gross;
        }

        return $this->car_price_gross !== null ? (float) $this->car_price_gross : null;
    }

    /** Percentage difference between effective car price and market median. Positive = above market. */
    public function priceDifference(): ?float
    {
        $median   = $this->median_price !== null ? (float) $this->median_price : null;
        $carPrice = $this->effectivePrice();

        if ($median === null || $median <= 0.0 || $carPrice === null) {
            return null;
        }

        return round(($carPrice - $median) / $median * 100.0, 2);
    }

    /** Human-readable price position relative to market. */
    public function priceSignal(): ?string
    {
        $diff = $this->priceDifference();

        if ($diff === null) {
            return null;
        }

        return match (true) {
            $diff >= 10.0  => 'overpriced',
            $diff >= 3.0   => 'slightly_high',
            $diff >= -5.0  => 'fair',
            default        => 'competitive',
        };
    }
}
