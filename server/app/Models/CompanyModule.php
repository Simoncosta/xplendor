<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Módulo ATIVO de uma empresa. Presença = ativo (a ausência de linha
 * significa desligado). Ligado/desligado pelo super-admin.
 */
class CompanyModule extends Model
{
    protected $fillable = [
        'company_id',
        'module_key',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
