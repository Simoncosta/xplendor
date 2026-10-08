<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bússola: a frase "O quê" de uma jogada, gerada pela IA no recálculo (até ao seguinte). */
class RestaurantCompassText extends Model
{
    protected $fillable = ['company_id', 'play_key', 'text', 'generated_at'];

    protected $casts = ['generated_at' => 'datetime'];
}
