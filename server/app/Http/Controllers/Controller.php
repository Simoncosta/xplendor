<?php

namespace App\Http\Controllers;

use App\Services\Tenancy\CompanyAccess;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    /**
     * A pessoa autenticada pode trabalhar nesta empresa (a própria, o root, ou a agência
     * gestora com relação ativa). Defesa em profundidade: o middleware tenant já decidiu
     * com a mesma regra (CompanyAccess).
     */
    protected function authorizeCompany(int|string|null $companyId): bool
    {
        return app(CompanyAccess::class)->allows(Auth::user(), $companyId);
    }

    /**
     * ACL: a pessoa autenticada tem a permissão nesta empresa (a mesma decisão do middleware
     * permission). Serve as flags can_* das respostas; a verificação a sério é a da rota.
     */
    protected function can(int|string $companyId, string $permission, array $context = []): bool
    {
        return app(\App\Access\Access::class)->can(Auth::user(), $companyId, $permission, $context)->allowed;
    }

    /**
     * ACL: uma permissão que depende do PEDIDO (por exemplo, criar um colaborador já com acesso
     * à plataforma). Recusa com o mesmo 403 do middleware permission.
     */
    protected function authorizePermission(int|string $companyId, string $permission, array $context = []): void
    {
        $decision = app(\App\Access\Access::class)->can(Auth::user(), $companyId, $permission, $context);
        if ($decision->denied()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(\App\Helpers\ApiResponse::forbidden((string) $decision->reason, $decision->code));
        }
    }

    /** A pessoa trabalha nesta empresa pela agência gestora (que nunca decide pelo cliente). */
    protected function viaAgency(int|string|null $companyId): bool
    {
        return app(CompanyAccess::class)->viaAgency(Auth::user(), $companyId);
    }
}
