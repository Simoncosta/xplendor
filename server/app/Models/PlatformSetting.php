<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Configurações: uma definição da plataforma (todas as empresas), só root. */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected $casts = ['value' => 'json'];
}
