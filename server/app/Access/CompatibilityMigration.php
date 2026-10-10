<?php

declare(strict_types=1);

namespace App\Access;

use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\PermissionProfile;
use App\Models\PermissionProfileEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ACL, F2: passa os dados de hoje para os perfis de compatibilidade, sem mudar nenhum acesso.
 *  · cria (ou atualiza) os perfis de sistema com as permissões do ficheiro derivado da
 *    fotografia (database/data/acl/perfis-compatibilidade.json);
 *  · cada utilizador sem perfil fica com o equivalente ao papel de hoje (o root não tem perfil);
 *  · quem trabalha numa agência fica também com o perfil dentro dos clientes;
 *  · cada relação de gestão sem teto fica com "Agência convidada (como hoje)".
 * Idempotente: só preenche o que está vazio. Corre na migração e no php artisan acl:migrate-profiles.
 */
final class CompatibilityMigration
{
    /** chave de sistema → [perfil derivado, lado] */
    public const SYSTEM = [
        PermissionProfile::ADMIN => [CompatibilityProfiles::CLIENT_ADMIN, PermissionProfile::SIDE_CLIENT],
        PermissionProfile::USER_COMPAT => [CompatibilityProfiles::CLIENT_USER, PermissionProfile::SIDE_CLIENT],
        PermissionProfile::AGENCY_ADMIN_COMPAT => [CompatibilityProfiles::AGENCY_ADMIN, PermissionProfile::SIDE_AGENCY],
        PermissionProfile::AGENCY_MEMBER_COMPAT => [CompatibilityProfiles::AGENCY_MEMBER, PermissionProfile::SIDE_AGENCY],
        PermissionProfile::CEILING_COMPAT => [CompatibilityProfiles::CEILING, PermissionProfile::SIDE_CEILING],
    ];

    public const DESCRIPTIONS = [
        PermissionProfile::ADMIN => 'Pode tudo na empresa. Perfil de sistema: não se edita, e cada empresa tem sempre pelo menos um administrador ativo.',
        PermissionProfile::USER_COMPAT => 'O que um utilizador podia fazer antes dos perfis, exceto a faturação da XPLENDOR, que é só do Administrador. O administrador pode trocá-lo por outro perfil quando quiser.',
        PermissionProfile::AGENCY_ADMIN_COMPAT => 'Administrador da agência, como antes dos perfis: trabalha em todos os clientes, dentro do que cada cliente permite.',
        PermissionProfile::AGENCY_MEMBER_COMPAT => 'Membro da agência, como antes dos perfis: não liga nem desliga integrações.',
        PermissionProfile::CEILING_COMPAT => 'O que a agência gestora podia fazer no cliente antes dos perfis: tudo, exceto as decisões do cliente, os acessos, os dados da empresa e a faturação da XPLENDOR.',
    ];

    /** @return array{perfis: int, utilizadores: int, agencia: int, relacoes: int} */
    public static function run(): array
    {
        return DB::transaction(function () {
            $profiles = self::ensureSystemProfiles();
            $users = 0;
            $agency = 0;

            $agencyCompanies = Company::whereNotNull('agency_enabled_at')->pluck('id')->all();
            User::where('role', '!=', 'root')->whereNull('profile_id')->orderBy('id')->chunkById(500, function ($chunk) use ($profiles, &$users) {
                foreach ($chunk as $u) {
                    $u->forceFill(['profile_id' => $profiles[$u->role === 'admin' ? PermissionProfile::ADMIN : PermissionProfile::USER_COMPAT]])->saveQuietly();
                    $users++;
                }
            });
            User::where('role', '!=', 'root')->whereNull('agency_profile_id')->whereIn('company_id', $agencyCompanies)->orderBy('id')->chunkById(500, function ($chunk) use ($profiles, &$agency) {
                foreach ($chunk as $u) {
                    $u->forceFill(['agency_profile_id' => $profiles[$u->role === 'admin' ? PermissionProfile::AGENCY_ADMIN_COMPAT : PermissionProfile::AGENCY_MEMBER_COMPAT]])->saveQuietly();
                    $agency++;
                }
            });
            $relations = CompanyManagement::whereNull('guest_profile_id')->update(['guest_profile_id' => $profiles[PermissionProfile::CEILING_COMPAT]]);

            $result = ['perfis' => count($profiles), 'utilizadores' => $users, 'agencia' => $agency, 'relacoes' => $relations];
            if ($users + $agency + $relations > 0) {
                PermissionProfileEvent::log('migracao_compatibilidade', null, null, null, $result);
            }

            return $result;
        });
    }

    /** @return array<string, int> chave de sistema → id */
    public static function ensureSystemProfiles(): array
    {
        $out = [];
        foreach (self::SYSTEM as $key => [$derived, $side]) {
            $profile = PermissionProfile::firstOrNew(['system_key' => $key]);
            $profile->fill([
                'company_id' => null, 'side' => $side, 'name' => CompatibilityProfiles::NAMES[$derived]['nome'],
                'description' => self::DESCRIPTIONS[$key], 'is_system' => true, 'is_suggestion' => false,
            ])->save();
            $profile->syncPermissions(array_keys(CompatibilityProfiles::allowed($derived)));
            $out[$key] = $profile->id;
        }

        return $out;
    }

    /** O perfil de sistema equivalente ao papel (para utilizadores novos e mudanças de papel). */
    public static function profileForRole(string $role): ?int
    {
        return $role === 'root' ? null : PermissionProfile::where('system_key', $role === 'admin' ? PermissionProfile::ADMIN : PermissionProfile::USER_COMPAT)->value('id');
    }

    public static function agencyProfileForRole(string $role): ?int
    {
        return $role === 'root' ? null : PermissionProfile::where('system_key', $role === 'admin' ? PermissionProfile::AGENCY_ADMIN_COMPAT : PermissionProfile::AGENCY_MEMBER_COMPAT)->value('id');
    }
}
