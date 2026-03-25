<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarSalePotentialScore extends Model
{
    protected $table = 'car_sale_potential_scores';

    protected $fillable = [
        'car_id',
        'company_id',
        'score',
        'classification',
        'score_breakdown',
        'price_vs_market',
        'days_in_stock_at_calc',
        'calculated_at',
        'triggered_by',
    ];

    protected $casts = [
        'score_breakdown'      => 'array',
        'calculated_at'        => 'datetime',
        'price_vs_market'      => 'decimal:2',
        'days_in_stock_at_calc' => 'integer',
        'score'                => 'integer',
    ];

    const CLASSIFICATIONS = [
        'hot'  => [70, 100],
        'warm' => [40, 69],
        'cold' => [0,  39],
    ];

    const TRIGGERED_BY = [
        'scheduled',
        'price_change',
        'status_change',
        'lead_created',
        'image_added',
        'manual',
        'promo_price_change',
    ];

    public static function classify(int $score): string
    {
        if ($score >= 70) return 'hot';
        if ($score >= 40) return 'warm';
        return 'cold';
    }

    // ── Relações ──────────────────────────────────────────────────────────────

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForCar($query, int $carId)
    {
        return $query->where('car_id', $carId);
    }

    public function scopeHot($query)
    {
        return $query->where('classification', 'hot');
    }

    public function scopeLatest($query)
    {
        return $query->orderByDesc('calculated_at');
    }
}
