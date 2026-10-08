<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — F3: um sinal calculado para "O que publicar e quando" (sugestão ou informação),
 * por empresa e loja. Só agregados; a tabela é substituída a cada cálculo
 * (RestaurantSignalService). A chave (signal_key) é estável entre cálculos.
 */
class RestaurantSignal extends Model
{
    public const KIND_SUGGESTION = 'suggestion';
    public const KIND_INFO = 'info';

    protected $guarded = ['id'];

    protected $casts = [
        'numbers' => 'array',
        'sample' => 'array',
        'suggested_date' => 'date',
        'priority' => 'integer',
        'computed_at' => 'datetime',
    ];
}
