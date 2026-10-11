<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bússola comum: a publicação criada a partir da Bússola e o veículo de que fala. */
class CompassPostSubject extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'editorial_post_id', 'subject_type', 'subject_id'];

    protected $casts = ['subject_id' => 'integer'];
}
