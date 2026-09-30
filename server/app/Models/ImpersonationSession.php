<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — IMPERSONATION: registo de auditoria de uma sessão (root a agir como user).
 * ended_at null = ativa. Ver migration create_impersonation_sessions.
 */
class ImpersonationSession extends Model
{
    protected $fillable = [
        'root_id', 'target_user_id', 'company_id', 'token_id',
        'reason', 'ip', 'user_agent', 'started_at', 'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function root(): BelongsTo
    {
        return $this->belongsTo(User::class, 'root_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * FONTE ÚNICA de "esta request é impersonation": a sessão ATIVA ligada ao token atual.
     * O token de impersonation tem name='impersonation' (pré-filtro barato) + linha ativa
     * nesta tabela (autoritativo). Devolve a sessão ou null.
     */
    public static function activeFor($user): ?self
    {
        if (! $user || ! method_exists($user, 'currentAccessToken')) {
            return null;
        }
        $token = $user->currentAccessToken();
        if (! $token || ($token->name ?? null) !== 'impersonation') {
            return null;
        }

        return static::where('token_id', $token->getKey())->whereNull('ended_at')->first();
    }
}
