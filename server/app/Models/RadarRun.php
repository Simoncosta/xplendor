<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Radar: uma recolha de uma fonte (também as simulações e os 304). Só contagens, o código HTTP
 * e os cabeçalhos de cache; nunca títulos nem conteúdo.
 */
class RadarRun extends Model
{
    protected $fillable = [
        'source_key', 'started_at', 'finished_at', 'status', 'dry_run', 'from_fixture', 'requests', 'http_status', 'etag',
        'last_modified', 'items_seen', 'items_new', 'items_repeated', 'items_blocked', 'items_out_of_scope', 'blocked_reasons', 'error',
    ];

    protected $casts = [
        'started_at' => 'datetime', 'finished_at' => 'datetime', 'dry_run' => 'boolean', 'from_fixture' => 'boolean',
        'requests' => 'integer', 'http_status' => 'integer', 'items_seen' => 'integer', 'items_new' => 'integer',
        'items_repeated' => 'integer', 'items_blocked' => 'integer', 'items_out_of_scope' => 'integer', 'blocked_reasons' => 'array',
    ];
}
