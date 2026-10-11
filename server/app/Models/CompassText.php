<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bússola comum: a frase "O quê" da IA de uma jogada, válida até ao próximo recálculo. */
class CompassText extends Model
{
    public $timestamps = false;

    protected $fillable = ['provider', 'company_id', 'play_key', 'text', 'generated_at'];

    protected $casts = ['generated_at' => 'datetime'];
}
