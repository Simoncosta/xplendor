<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * XPLENDOR — Auditoria: um achado. Estados: aberto, aceite (vai ser corrigido), ignorado (com
 * motivo; volta a abrir se a evidência mudar), corrigido (marcado; a corrida seguinte confirma) e
 * resolvido (automático, quando a regra deixa de o encontrar). A impressão digital (regra +
 * entidade + chave da evidência) evita duplicados entre corridas.
 */
class AuditFinding extends Model
{
    public const STATES = ['aberto', 'aceite', 'ignorado', 'corrigido', 'resolvido'];
    /** Os que ainda pedem atenção (a coluna "Auditoria" das listas e o Pareto por omissão). */
    public const OPEN = ['aberto', 'aceite', 'corrigido'];
    public const SEVERITIES = ['alta', 'media', 'baixa', 'info'];
    public const SEVERITY_RANK = ['alta' => 3, 'media' => 2, 'baixa' => 1, 'info' => 0];

    protected $fillable = [
        'company_id', 'rule', 'area', 'severity', 'entity_type', 'entity_id', 'entity_label', 'title', 'seen', 'stake',
        'evidence', 'evidence_signature', 'why', 'suggestion', 'impact_cents', 'impact_pending_cents', 'weight_cents', 'fix_url',
        'fix_label', 'fix_action', 'fingerprint', 'state', 'state_by', 'state_at', 'state_reason', 'note',
        'last_run_id', 'first_seen_at', 'last_seen_at', 'resolved_at', 'assigned_to', 'assigned_by', 'assigned_at',
        'resolved_cents', 'resolved_by',
    ];

    protected $casts = [
        'evidence' => 'array',
        'fix_action' => 'array',
        'impact_cents' => 'integer',
        'impact_pending_cents' => 'integer',
        'weight_cents' => 'integer',
        'state_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'assigned_at' => 'datetime',
        'resolved_cents' => 'integer',
    ];

    public function refs(): HasMany
    {
        return $this->hasMany(AuditFindingRef::class, 'finding_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AuditFindingEvent::class, 'finding_id');
    }

    public function stateUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'state_by');
    }

    /**
     * O lugar no Pareto: primeiro o dinheiro em jogo (impacto), depois o impacto a confirmar e só
     * depois o peso (vendas ou compras ligadas). [escalão, valor]
     *
     * @return array{0:int, 1:int}
     */
    public static function paretoKey(?int $impact, ?int $pending, ?int $weight): array
    {
        return match (true) {
            ($impact ?? 0) > 0 => [0, (int) $impact],
            ($pending ?? 0) > 0 => [1, (int) $pending],
            default => [2, (int) ($weight ?? 0)],
        };
    }
}
