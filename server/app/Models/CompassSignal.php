<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bússola comum: um sinal calculado de um fornecedor (o stand). Só contagens e números, nunca dados pessoais. */
class CompassSignal extends Model
{
    protected $fillable = ['provider', 'company_id', 'signal_key', 'type', 'subject_type', 'subject_id', 'vehicle_type', 'confidence', 'numbers', 'computed_at'];

    protected $casts = ['numbers' => 'array', 'computed_at' => 'datetime', 'subject_id' => 'integer'];
}
