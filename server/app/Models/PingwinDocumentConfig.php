<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Tipo de documento PingWin (Definições→Documentos), só leitura.
 * UNIQUE (company_id, external_id) → UPSERT idempotente no sync.
 */
class PingwinDocumentConfig extends Model
{
    protected $fillable = [
        'company_id', 'external_id', 'code', 'description',
        'entitytype', 'fiscaltype', 'fiscaltype_description', 'deleted', 'synced_at',
    ];

    protected $casts = [
        'deleted'   => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
