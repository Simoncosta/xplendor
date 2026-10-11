<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bússola comum: a publicação e o veículo de que fala. source: "bussola" (criada a partir de uma
 * jogada) ou "ficheiros" (usa fotografias da pasta do veículo nos Ficheiros).
 */
class CompassPostSubject extends Model
{
    public const UPDATED_AT = null;
    public const SOURCE_BUSSOLA = 'bussola';
    public const SOURCE_FILES = 'ficheiros';

    protected $fillable = ['company_id', 'editorial_post_id', 'subject_type', 'subject_id', 'source'];

    protected $casts = ['subject_id' => 'integer'];
}
