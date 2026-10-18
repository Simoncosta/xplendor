<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Auditoria v2: UMA alteração às unidades de um artigo no PingWin (criar, fator,
 * anular, unidade de compra por defeito), iniciada por uma pessoa, com leitura antes e releitura
 * depois. Estados: queued → running → ok | mismatch (a releitura não bate) | refused (guarda) | failed.
 */
class PingwinUnitWrite extends Model
{
    public const OPS = ['create', 'edit_factor', 'void', 'set_purchase'];
    public const ACTIVE = ['queued', 'running'];
    public const FINAL = ['ok', 'mismatch', 'refused', 'failed'];

    protected $fillable = [
        'company_id', 'product_pingwin_id', 'product_code', 'user_id', 'profile_id', 'op', 'data', 'unit_pingwin_id',
        'before', 'after', 'progress', 'status', 'note', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'data' => 'array', 'before' => 'array', 'after' => 'array', 'progress' => 'array',
        'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id, 'op' => $this->op, 'status' => $this->status, 'data' => $this->data, 'unit_id' => $this->unit_pingwin_id,
            'before' => $this->before, 'after' => $this->after, 'progress' => $this->progress ?? [], 'note' => $this->note,
            'error' => $this->error, 'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
