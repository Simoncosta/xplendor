<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Auditoria/estado de uma ESCRITA de config de documento no PingWin
 * (Fase D1: editar maindataset). Alvo de polling da UI enquanto o worker executa.
 */
class PingwinDocconfigWrite extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'action', 'docconfig_id', 'code', 'description',
        'fields', 'children', 'docaccount', 'status', 'error_message', 'finished_at',
    ];

    protected $casts = [
        'fields'      => 'array',
        'children'    => 'array',
        'docaccount'  => 'array',
        'finished_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
