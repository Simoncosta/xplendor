<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — Uma execução da sync de documentos de fornecedor (madrugada ou manual).
 */
class PingwinDocumentSyncRun extends Model
{
    public const STATUS_QUEUED  = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_OK      = 'ok';
    public const STATUS_FAILED  = 'failed';

    public const TRIGGER_NIGHTLY = 'nightly';
    public const TRIGGER_MANUAL  = 'manual';

    protected $fillable = [
        'company_id', 'start_date', 'end_date', 'trigger', 'status',
        'docs_count', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'docs_count'  => 'integer',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    /** Forma usada pela API (datas Y-m-d, instantes ISO). */
    public function toApi(): array
    {
        return [
            'id'          => $this->id,
            'start_date'  => $this->start_date?->toDateString(),
            'end_date'    => $this->end_date?->toDateString(),
            'trigger'     => $this->trigger,
            'status'      => $this->status,
            'docs_count'  => $this->docs_count,
            'error'       => $this->error,
            'started_at'  => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
