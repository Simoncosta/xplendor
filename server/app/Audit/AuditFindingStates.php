<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AuditFinding;
use App\Models\AuditFindingEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Auditoria: as mudanças de estado feitas por uma pessoa. "Resolvido" é sempre
 * automático (a regra deixou de o encontrar); "Marcado como corrigido" espera pela corrida seguinte.
 */
final class AuditFindingStates
{
    /** ação → [estado de chegada, estados de partida] */
    public const ACTIONS = [
        'aceitar' => ['aceite', ['aberto']],
        'ignorar' => ['ignorado', ['aberto', 'aceite']],
        'corrigido' => ['corrigido', ['aberto', 'aceite']],
        'reabrir' => ['aberto', ['aceite', 'ignorado', 'corrigido']],
    ];

    public static function apply(AuditFinding $finding, string $action, ?string $reason, User $user): AuditFinding
    {
        [$to, $from] = self::ACTIONS[$action] ?? [null, []];
        if ($to === null) {
            throw ValidationException::withMessages(['action' => ['Ação desconhecida.']]);
        }
        if ($finding->state === 'resolvido') {
            throw ValidationException::withMessages(['action' => ['Este achado já está resolvido: a auditoria deixou de o encontrar.']]);
        }
        if (! in_array($finding->state, $from, true)) {
            throw ValidationException::withMessages(['action' => ['Esta mudança não é possível a partir do estado atual.']]);
        }
        $reason = trim((string) $reason);
        if ($action === 'ignorar' && $reason === '') {
            throw ValidationException::withMessages(['reason' => ['Diga porque ignora este achado.']]);
        }

        $prev = $finding->state;
        // Euros resolvidos: ao corrigir, guarda-se o € em jogo nesse momento e quem corrigiu (conta
        // quando a corrida seguinte o confirmar); reabrir limpa-os.
        $money = match ($action) {
            'corrigido' => ['resolved_cents' => $finding->impact_cents ?? $finding->impact_pending_cents ?? 0, 'resolved_by' => $user->id],
            'reabrir', 'ignorar' => ['resolved_cents' => null, 'resolved_by' => null],
            default => [],
        };
        $finding->forceFill([
            'state' => $to, 'state_by' => $user->id, 'state_at' => now(),
            'state_reason' => $reason !== '' ? $reason : null, 'note' => null,
        ] + $money)->save();
        AuditFindingEvent::create([
            'finding_id' => $finding->id, 'company_id' => $finding->company_id, 'from_state' => $prev, 'to_state' => $to,
            'user_id' => $user->id, 'reason' => $reason !== '' ? $reason : null, 'created_at' => now(),
        ]);

        return $finding;
    }
}
