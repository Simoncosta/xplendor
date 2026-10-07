<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\ImpersonationSession;
use App\Models\User;
use OwenIt\Auditing\Resolvers\UserResolver;

/**
 * Auditoria com a PESSOA REAL: em impersonation, o token é do utilizador-alvo, mas quem
 * age é o root da sessão. Fora de impersonation, o utilizador autenticado (como o
 * resolver de origem). Quem trabalha pela agência age com a própria conta.
 */
class RealPersonResolver extends UserResolver
{
    public static function resolve()
    {
        $user = parent::resolve();
        $session = $user instanceof User ? ImpersonationSession::activeFor($user) : null;

        return $session ? (User::find($session->root_id) ?? $user) : $user;
    }
}
