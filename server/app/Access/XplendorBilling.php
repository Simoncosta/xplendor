<?php

declare(strict_types=1);

namespace App\Access;

use Illuminate\Http\Request;

/**
 * A faturação da XPLENDOR (orçamentos e cobranças) é só do Administrador da empresa. Para as
 * respostas que a misturam com outras áreas (o pedido de suporte com o orçamento, a despesa
 * que espelha uma cobrança): o resto vai a todos, a parte da faturação só a quem a vê.
 */
final class XplendorBilling
{
    public static function visibleTo(Request $request, int $companyId): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        if ($user->isRoot()) {
            return true; // a equipa XPLENDOR fez o orçamento e a cobrança (no /admin e na conversa)
        }

        $key = "xplendor_billing:{$user->id}:{$companyId}"; // uma vez por pedido (as listas pedem-no por linha)
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, app(Access::class)->can($user, $companyId, 'faturacao_xplendor.ver')->allowed);
        }

        return (bool) $request->attributes->get($key);
    }
}
