<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Finanças: uma entidade tirada dos "Documentos habituais em falta". "Não vem este mês"
 * (scope mes) vale só para esse mês; "Não é habitual" (scope sempre) vale desse mês em diante. Repor
 * marca removed_at (o histórico fica).
 */
class FinanceHabitualExclusion extends Model
{
    public const MONTH = 'mes';
    public const ALWAYS = 'sempre';

    protected $fillable = ['company_id', 'entity_pingwin_id', 'entity_name', 'scope', 'month', 'avg_cents', 'reason', 'created_by', 'removed_at', 'removed_by'];

    protected $casts = ['avg_cents' => 'integer', 'removed_at' => 'datetime'];

    /** As que valem para um mês. */
    public function scopeActiveFor(Builder $q, int $companyId, string $ym): Builder
    {
        return $q->where('company_id', $companyId)->whereNull('removed_at')
            ->where(fn ($w) => $w->where(fn ($m) => $m->where('scope', self::MONTH)->where('month', $ym))
                ->orWhere(fn ($s) => $s->where('scope', self::ALWAYS)->where('month', '<=', $ym)));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}
