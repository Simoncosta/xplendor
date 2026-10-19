<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Configurações: uma definição de uma empresa (o valor em JSON). O histórico está em setting_events. */
class CompanySetting extends Model
{
    protected $fillable = ['company_id', 'key', 'value', 'updated_by'];

    protected $casts = ['value' => 'json'];
}
