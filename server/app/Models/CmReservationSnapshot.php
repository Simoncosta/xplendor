<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Fatia 6a: uma fotografia das reservas futuras de uma loja e dia (só números), por leitura. */
class CmReservationSnapshot extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['read_at' => 'datetime', 'by_status' => 'array', 'by_channel' => 'array'];
}
