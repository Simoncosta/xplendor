<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Configuração por empresa de uma regra do motor de recomendações. */
class CompanyRecommendationSetting extends Model
{
    protected $fillable = ['company_id', 'rule_key', 'enabled', 'params'];

    protected $casts = [
        'enabled' => 'boolean',
        'params' => 'array',
    ];
}
