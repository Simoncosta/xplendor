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

    /** A pessoa trabalha nesta empresa pela agência gestora (que nunca decide pelo cliente). */
    protected function viaAgency(int|string|null $companyId): bool
    {
        return app(CompanyAccess::class)->viaAgency(Auth::user(), $companyId);
    }
}
