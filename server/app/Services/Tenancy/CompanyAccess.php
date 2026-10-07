<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\User;

/**
 * Portão central de tenancy: decide se um utilizador pode trabalhar numa empresa e a que
 * título. É a única regra; o middleware tenant, o authorizeCompany dos controllers e os
 * ajudantes de papel dos serviços chamam-na.
 *  · own: a empresa do próprio utilizador (também em impersonation: o token é do alvo).
 *  · root: o dono da plataforma passa sempre, em qualquer empresa.
 *  · agency: a empresa do utilizador é agência e tem uma relação ATIVA com esta empresa
 *    (e, se o âmbito for "assigned", a pessoa está atribuída ao cliente).
 * Relação pendente, recusada, retirada, terminada ou expirada não dá acesso.
 * O resultado fica guardado no pedido atual (várias verificações, uma só consulta).
 */
class CompanyAccess
{
    public const OWN = 'own';
    public const ROOT = 'root';
    public const AGENCY = 'agency';

    public function kind(?User $user, int|string|null $companyId): ?string
    {
        $companyId = (int) $companyId;
        if (! $user || $companyId <= 0) {
            return null;
        }
        if ((int) $user->company_id === $companyId) {
            return self::OWN;
        }
        if ($user->role === 'root') {
            return self::ROOT;
        }

        return $this->remember("{$user->id}:{$companyId}", fn () => $this->agencyManages($user, $companyId) ? self::AGENCY : null);
    }

    public function allows(?User $user, int|string|null $companyId): bool
    {
        return $this->kind($user, $companyId) !== null;
    }

    /** A pessoa trabalha nesta empresa pela agência gestora (relação ativa). */
    public function viaAgency(?User $user, int|string|null $companyId): bool
    {
        return $this->kind($user, $companyId) === self::AGENCY;
    }

    /** A relação ativa da empresa (ou null). */
    public function management(int $managedCompanyId): ?CompanyManagement
    {
        return CompanyManagement::active()->where('managed_company_id', $managedCompanyId)->first();
    }

    /** Empresas que a agência do utilizador gere e onde ele pode trabalhar. @return int[] */
    public function managedCompanyIds(User $user): array
    {
        if (! $user->company_id || ! Company::whereKey($user->company_id)->whereNotNull('agency_enabled_at')->exists()) {
            return [];
        }

        return CompanyManagement::active()
            ->where('agency_company_id', $user->company_id)
            ->where(fn ($q) => $q->where('team_scope', CompanyManagement::SCOPE_ALL)
                ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id)))
            ->pluck('managed_company_id')->map(fn ($id) => (int) $id)->all();
    }

    private function agencyManages(User $user, int $companyId): bool
    {
        if (! $user->company_id) {
            return false;
        }
        $m = CompanyManagement::active()
            ->where('managed_company_id', $companyId)
            ->where('agency_company_id', $user->company_id)
            ->whereHas('agency', fn ($q) => $q->whereNotNull('agency_enabled_at'))
            ->first();
        if (! $m) {
            return false;
        }

        return $m->team_scope !== CompanyManagement::SCOPE_ASSIGNED
            || $m->members()->where('user_id', $user->id)->exists();
    }

    /** Guarda no pedido HTTP atual (nunca entre pedidos nem em jobs). */
    private function remember(string $key, \Closure $resolve): ?string
    {
        $request = request();
        if (! $request->route()) {
            return $resolve();
        }
        $cache = $request->attributes->get('company_access', []);
        if (! array_key_exists($key, $cache)) {
            $cache[$key] = $resolve();
            $request->attributes->set('company_access', $cache);
        }

        return $cache[$key];
    }
}
