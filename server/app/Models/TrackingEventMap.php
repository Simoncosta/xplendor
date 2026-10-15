<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — Auditoria (tracking): o nome de um evento no GA4 de um site → o tipo na XPLENDOR, com
 * filtros opcionais sobre o page_context. Sem linhas para a empresa, os nomes são iguais.
 */
class TrackingEventMap extends Model
{
    protected $fillable = ['company_id', 'ga4_event', 'xplendor_type', 'filters', 'is_active'];

    protected $casts = ['filters' => 'array', 'is_active' => 'boolean'];
}
