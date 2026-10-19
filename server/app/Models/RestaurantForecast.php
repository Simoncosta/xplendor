<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Fatia 6a: a previsão de uma loja e dia (pessoas ou faturação), com o intervalo e o modelo. */
class RestaurantForecast extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['computed_at' => 'datetime', 'value' => 'float', 'low' => 'float', 'high' => 'float'];
}
