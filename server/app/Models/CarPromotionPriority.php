<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Marcação de "prioridade de promoção" para uma viatura.
 *
 * Cada toggle ON cria nova row; toggle OFF faz UPDATE is_active=false +
 * carimba `unmarked_*`. A unicidade do estado activo é garantida pelo
 * `StockPromotionService` (MariaDB não suporta partial unique).
 *
 * Auditável via owen-it/laravel-auditing — histórico em `audits`.
 */
class CarPromotionPriority extends Model implements AuditableContract
{
    use Auditable;

    protected $fillable = [
        'company_id',
        'car_id',
        'marked_by_user_id',
        'marked_at',
        'note',
        'is_active',
        'unmarked_at',
        'unmarked_by_user_id',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'marked_at'   => 'datetime',
        'unmarked_at' => 'datetime',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }

    public function unmarkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unmarked_by_user_id');
    }

    /** Apenas marcações em vigor — usado em todos os reads do Relatório A. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
