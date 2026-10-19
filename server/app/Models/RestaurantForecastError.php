<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Fatia 6a: o erro medido de um modelo numa loja (backtest das últimas 8 semanas). */
class RestaurantForecastError extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['computed_at' => 'datetime', 'mape' => 'float', 'p10' => 'float', 'p90' => 'float'];
}
