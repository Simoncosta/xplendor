<?php

declare(strict_types=1);

namespace App\Access;

use App\Models\Company;
use App\Models\ImpersonationSession;
use App\Models\PermissionProfile;
use App\Models\ProfilePermission;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Tenancy\CompanyAccess;

/**
 * ACL: a decisão "esta pessoa pode fazer isto nesta empresa?", num só sítio.
 * Desenho: documents/ACL-DESENHO.md. Avalia por esta ordem:
 *   1. empresa: CompanyAccess::kind tem de ser own, root ou agency (senão, não);
 *   2. plataforma: só o root;
 *   3. módulos: os módulos pedidos (os do ensure_module da rota) têm de estar ativos;
 *   4. impersonation: as rotas sensíveis (block_when_impersonating) ficam recusadas;
 *   5. root (D1): passa em todas as permissões; noutras empresas, as decisões do cliente
 *      (aprovar conteúdos, aceitar orçamentos, aceitar ou terminar a gestão) exigem uma pessoa
 *      do próprio cliente, e a faturação da XPLENDOR é só do Administrador da empresa (o root
 *      trata-a no /admin); na própria empresa, o root conta como administrador;
 *   5b. só do Administrador (Permissions::ADMIN_ONLY_AREAS): a faturação da XPLENDOR é do
 *      perfil Administrador da própria empresa, e de mais ninguém (nem a agência);
 *   6. perfil: own → o perfil do utilizador (users.profile_id; + editorial.aprovar se for
 *      aprovador); agency → o perfil dentro dos clientes (users.agency_profile_id) ∩ o teto
 *      que o cliente deu à agência (company_managements.guest_profile_id). Sem perfil
 *      gravado, vale o perfil de compatibilidade do papel (o mesmo que a migração F2 dá).
 *
 * Contexto (opcional): route ("MÉTODO api/v1/companies/…"), modules (string[]),
 * sensitive (bool), self (bool: a rota é sobre a própria conta). Sem pedido HTTP (jobs, comandos), não há Access: os serviços não podem
 * depender do utilizador autenticado.
 */
class Access
{
    public const ADMIN_ONLY_MESSAGE = 'Só o Administrador da empresa vê e aprova a faturação da XPLENDOR.';

    /** @var array<string, array<string, true>> cache por pedido */
    private array $memo = [];

    /** @var array<int, array<string, true>> permissões de cada perfil (cache por pedido) */
    private array $profiles = [];

    private ?int $adminProfileId = null;

    public function __construct(
        private readonly CompanyAccess $companies,
        private readonly CompanyModuleService $modules,
    ) {}

    public function can(?User $user, int|string|null $companyId, string $permission, array $context = []): Decision
    {
        $companyId = (int) $companyId;
        if (! Permissions::isValid($permission)) {
            return Decision::deny("Permissão desconhecida: {$permission}.", Decision::UNKNOWN);
        }
        $kind = $this->companies->kind($user, $companyId);
        if ($kind === null) {
            return Decision::deny('Acesso negado: utilizador inválido.', Decision::TENANT);
        }
        if (Permissions::area($permission) === 'plataforma' && $user->role !== 'root') {
            return Decision::deny('Só a equipa da plataforma pode fazer isto.', Decision::PLATFORM);
        }
        foreach ($user->role === 'root' ? [] : (array) ($context['modules'] ?? []) as $module) { // o root não é filtrado por módulos (regra de hoje)
            if (! $this->modules->isEnabled($companyId, (string) $module)) {
                return Decision::deny('Este módulo não está ativo para a empresa.', Decision::MODULE);
            }
        }
        if (($context['sensitive'] ?? false) && ImpersonationSession::activeFor($user)) {
            return Decision::deny('Esta ação não é permitida durante a impersonation. Sai da impersonation para a executar.', Decision::IMPERSONATION);
        }

        if ($user->role === 'root') {
            // D1: noutras empresas, as decisões do cliente são do cliente. Na PRÓPRIA empresa, o
            // root conta como administrador, também nas decisões (aprovar os artigos da XPLENDOR).
            if ($kind !== CompanyAccess::OWN && Permissions::isAdminOnly($permission)) {
                return Decision::deny(self::ADMIN_ONLY_MESSAGE . ' A equipa XPLENDOR trata-a no /admin.', Decision::ADMIN_ONLY);
            }

            return Permissions::isClientDecision($permission) && $kind !== CompanyAccess::OWN
                ? Decision::deny('Esta decisão é do cliente: tem de ser tomada por uma pessoa da própria empresa.', Decision::CLIENT_DECISION)
                : Decision::allow();
        }

        if (Permissions::isAdminOnly($permission)) {
            return $kind === CompanyAccess::OWN && $this->isCompanyAdmin($user)
                ? Decision::allow()
                : Decision::deny(self::ADMIN_ONLY_MESSAGE, Decision::ADMIN_ONLY);
        }

        if (in_array($permission, Permissions::BASE, true)) {
            return Decision::allow(); // base: quem trabalha na empresa vê-a sempre
        }
        if (($context['self'] ?? false) && $kind === CompanyAccess::OWN) {
            return Decision::allow(); // a própria conta (o FormRequest continua a limitar o que se pode mudar)
        }

        $allowed = $this->permissionsFor($user, $kind, $companyId);
        if (isset($allowed[$permission])) {
            return Decision::allow();
        }
        if ($kind === CompanyAccess::OWN && in_array($permission, Permissions::IMPERSONATION_GRANTS, true) && ImpersonationSession::activeFor($user)) {
            return Decision::allow(); // em sessão como cliente, a equipa edita os conteúdos (regra de hoje)
        }
        if ($permission === 'empresa.editar' && $kind === CompanyAccess::AGENCY && $this->companies->agencyEditsBasics($user, $companyId)) {
            return Decision::allow(); // a agência edita os dados básicos das empresas que criou, enquanto não houver admin
        }

        return $this->denial($user, $kind, $companyId, $permission);
    }

    /** As permissões efetivas da pessoa nesta empresa (sem módulos nem regras transversais). @return array<string, true> */
    public function permissionsFor(User $user, string $kind, int $companyId): array
    {
        $key = "{$user->id}:{$kind}:{$companyId}";
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        if ($kind === CompanyAccess::AGENCY) {
            $own = $user->agency_profile_id
                ? $this->profilePermissions((int) $user->agency_profile_id)
                : CompatibilityProfiles::allowed($user->role === 'admin' ? CompatibilityProfiles::AGENCY_ADMIN : CompatibilityProfiles::AGENCY_MEMBER);
            $set = array_intersect_key($own, $this->ceilingFor($companyId));
        } else {
            $set = $user->profile_id
                ? $this->profilePermissions((int) $user->profile_id)
                : CompatibilityProfiles::allowed($user->role === 'admin' ? CompatibilityProfiles::CLIENT_ADMIN : CompatibilityProfiles::CLIENT_USER);
            if ($user->can_approve_content) {
                foreach (Permissions::APPROVER_GRANTS as $grant) { // D6: o aprovador também aprova o blog
                    $set[$grant] = true;
                }
            }
        }

        return $this->memo[$key] = $set;
    }

    /** Tem o perfil Administrador (sem perfil gravado, vale o papel admin, como na migração F2). */
    public function isCompanyAdmin(User $user): bool
    {
        if (! $user->profile_id) {
            return $user->role === 'admin';
        }

        $this->adminProfileId ??= (int) PermissionProfile::where('system_key', PermissionProfile::ADMIN)->value('id');

        return (int) $user->profile_id === $this->adminProfileId;
    }

    /** O teto que o cliente deu à agência gestora (a relação ativa). @return array<string, true> */
    public function ceilingFor(int $companyId): array
    {
        $profileId = $this->companies->management($companyId)?->guest_profile_id;

        return $profileId ? $this->profilePermissions((int) $profileId) : CompatibilityProfiles::allowed(CompatibilityProfiles::CEILING);
    }

    /** @return array<string, true> */
    private function profilePermissions(int $profileId): array
    {
        if (! isset($this->profiles[$profileId])) {
            $set = [];
            foreach (ProfilePermission::where('profile_id', $profileId)->get(['area', 'action']) as $p) {
                $set["{$p->area}.{$p->action}"] = true;
            }
            $this->profiles[$profileId] = $set;
        }

        return $this->profiles[$profileId];
    }

    private function denial(User $user, string $kind, int $companyId, string $permission): Decision
    {
        if ($kind === CompanyAccess::AGENCY) {
            if (Permissions::isClientDecision($permission)) {
                return Decision::deny('Esta decisão é do cliente: a agência gestora não a pode tomar.', Decision::CLIENT_DECISION);
            }
            if (! isset($this->ceilingFor($companyId)[$permission])) {
                $name = Company::whereKey($companyId)->value('fiscal_name') ?: 'O cliente';

                return Decision::deny("{$name} não deu acesso a esta agência para " . Permissions::phrase($permission) . '.', Decision::CEILING);
            }

            return Decision::deny('O seu perfil na agência não permite ' . Permissions::phrase($permission) . '.', Decision::PROFILE);
        }

        return Decision::deny('O seu perfil não permite ' . Permissions::phrase($permission) . '.', Decision::PROFILE);
    }

    public function flush(): void
    {
        $this->memo = [];
        $this->profiles = [];
        $this->adminProfileId = null;
    }
}
