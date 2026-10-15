<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Auditoria: uma corrida de regras (uma de cada vez por empresa e âmbito). Estados:
 * queued → running → done | failed. Cada regra é um passo com estado, contagens e tempo; a falha
 * de uma regra não pára as outras.
 */
class AuditRun extends Model
{
    public const ACTIVE = ['queued', 'running'];

    protected $fillable = [
        'company_id', 'area', 'origin', 'scope_key', 'rules', 'requested_by', 'status', 'steps',
        'new_count', 'resolved_count', 'open_count', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'rules' => 'array',
        'steps' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'area' => $this->area,
            'origin' => $this->origin,
            'rules' => $this->rules ?? [],
            'status' => $this->status,
            'steps' => $this->steps ?? [],
            'new_count' => (int) $this->new_count,
            'resolved_count' => (int) $this->resolved_count,
            'open_count' => (int) $this->open_count,
            'error' => $this->error,
            'requested_by' => $this->requester?->name,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
