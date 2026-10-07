<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — F1-3: retrato da qualidade dos dados de restauração de uma empresa (ver
 * RestaurantDataQualityService).
 */
class RestaurantDataQuality extends Model
{
    protected $table = 'restaurant_data_quality';

    protected $fillable = [
        'company_id', 'catalog_sold_count', 'catalog_missing_count', 'catalog_coverage_pct',
        'days_checked', 'days_ok', 'days_marked', 'families_total', 'families_unconfirmed',
        'revenue_unconfirmed_pct', 'locations', 'computed_at',
    ];

    protected $casts = [
        'catalog_coverage_pct' => 'float',
        'revenue_unconfirmed_pct' => 'float',
        'locations' => 'array',
        'computed_at' => 'datetime',
    ];
}
