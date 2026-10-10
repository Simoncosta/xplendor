<?php

declare(strict_types=1);

namespace App\Access;

use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\PermissionProfile;
use App\Models\PermissionProfileEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ACL (F5): os perfis de uma empresa (Utilizadores › Perfis) e o teto da agência gestora.
 *  · D12: cada empresa tem SEMPRE pelo menos um administrador ativo; o perfil Administrador
 *    é de sistema e não se edita.
 *  · D13: um perfil novo parte de uma sugestão ou de um perfil vazio; a sugestão nunca é
 *    imposta (é copiada para um perfil da empresa, que se edita à vontade).
 *  · Até haver mais do que o papel no código antigo, o perfil Administrador anda com o papel
 *    admin e qualquer outro perfil com o papel user.
 * Cada alteração fica em permission_profile_events (quem, quando, o quê).
 */
final class ProfileService
{
    /** O que cada lado pode ter: a agência nunca toma decisões do cliente, e o lado do cliente não tem ações da agência. */
    public static function allowedFor(string $side): array
    {
        return array_values(array_filter(Permissions::assignable(), function (string $p) use ($side) {
            $area = Permissions::area($p);
            if (Permissions::isAdminOnly($p)) {
                return false; // só o perfil Administrador (de sistema) a tem
            }
            if ($side === PermissionProfile::SIDE_CLIENT) {
                return $area !== 'agencia';
            }

            // Agência e teto: nunca as decisões do cliente, os acessos do cliente nem a faturação da XPLENDOR.
            return ! Permissions::isClientDecision($p) && $p !== 'utilizadores.configurar' && $area !== 'faturacao_xplendor';
        }));
    }

    public static function isAgency(Company $company): bool
    {
        return $company->agency_enabled_at !== null;
    }

    /** Os perfis que esta empresa pode usar, por lado. */
    public function profilesFor(Company $company)
    {
        $sides = [PermissionProfile::SIDE_CLIENT, PermissionProfile::SIDE_CEILING];
        if (self::isAgency($company)) {
            $sides[] = PermissionProfile::SIDE_AGENCY;
        }

        return PermissionProfile::query()
            ->whereIn('side', $sides)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->with('permissions')
            ->orderByDesc('is_system')->orderBy('is_suggestion')->orderBy('name')
            ->get();
    }

    public function create(Company $company, array $data, User $actor): PermissionProfile
    {
        $side = $data['side'];
        if ($side === PermissionProfile::SIDE_AGENCY && ! self::isAgency($company)) {
            throw ValidationException::withMessages(['side' => ['Só uma agência tem perfis para trabalhar nos clientes.']]);
        }
        $this->assertUniqueName($company, $data['name']);
        self::assertNoAdminOnly($data['permissions'] ?? []);
        $from = isset($data['from_profile_id']) ? PermissionProfile::find($data['from_profile_id']) : null;

        return DB::transaction(function () use ($company, $data, $side, $actor, $from) {
            $profile = PermissionProfile::create([
                'company_id' => $company->id, 'side' => $side, 'name' => trim($data['name']), 'description' => $data['description'] ?? null,
                'is_system' => false, 'is_suggestion' => false, 'created_by_user_id' => $actor->id,
                'only_assigned_clients' => (bool) ($data['only_assigned_clients'] ?? $from?->only_assigned_clients ?? false),
            ]);
            $profile->syncPermissions(array_values(array_intersect($data['permissions'] ?? [], self::allowedFor($side))));
            PermissionProfileEvent::log('perfil_criado', $company->id, $profile->id, null, [
                'nome' => $profile->name, 'lado' => $side, 'a_partir_de' => $from?->name, 'permissoes' => $profile->permissionKeys(),
            ]);

            return $profile;
        });
    }

    public function update(Company $company, PermissionProfile $profile, array $data, User $actor): PermissionProfile
    {
        $this->assertEditable($company, $profile);
        if (isset($data['name']) && trim($data['name']) !== $profile->name) {
            $this->assertUniqueName($company, $data['name'], $profile->id);
        }
        self::assertNoAdminOnly($data['permissions'] ?? []);

        return DB::transaction(function () use ($company, $profile, $data) {
            $before = $profile->permissionKeys();
            $profile->fill(array_filter(['name' => isset($data['name']) ? trim($data['name']) : null, 'description' => $data['description'] ?? null], fn ($v) => $v !== null))->save();
            if (array_key_exists('permissions', $data)) {
                $profile->syncPermissions(array_values(array_intersect($data['permissions'], self::allowedFor($profile->side))));
            }
            $after = $profile->permissionKeys();
            PermissionProfileEvent::log('perfil_alterado', $company->id, $profile->id, null, [
                'nome' => $profile->name, 'acrescentadas' => array_values(array_diff($after, $before)), 'retiradas' => array_values(array_diff($before, $after)),
            ]);

            return $profile->fresh('permissions');
        });
    }

    public function delete(Company $company, PermissionProfile $profile): void
    {
        $this->assertEditable($company, $profile);
        $users = User::where('profile_id', $profile->id)->orWhere('agency_profile_id', $profile->id)->count();
        $ceilings = CompanyManagement::where('guest_profile_id', $profile->id)->count();
        if ($users + $ceilings > 0) {
            throw ValidationException::withMessages(['profile' => [$users > 0
                ? "Este perfil está atribuído a {$users} " . ($users === 1 ? 'pessoa' : 'pessoas') . ': atribua-lhes outro perfil antes de o apagar.'
                : 'Este perfil é o teto da agência gestora: escolha outro teto antes de o apagar.']]);
        }
        PermissionProfileEvent::log('perfil_apagado', $company->id, null, null, ['nome' => $profile->name, 'permissoes' => $profile->permissionKeys()]);
        $profile->delete();
    }

    /** Atribui o perfil (na própria empresa, ou dentro dos clientes para quem é de uma agência). */
    public function assign(Company $company, User $target, PermissionProfile $profile, User $actor): User
    {
        if ((int) $target->company_id !== (int) $company->id || $target->isRoot()) {
            throw ValidationException::withMessages(['user' => ['Esta pessoa não é desta empresa.']]);
        }
        if (! $this->usable($company, $profile) || $profile->side === PermissionProfile::SIDE_CEILING) {
            throw ValidationException::withMessages(['profile_id' => ['Este perfil não se pode atribuir aqui. As sugestões copiam-se primeiro para um perfil da empresa.']]);
        }

        return DB::transaction(function () use ($company, $target, $profile) {
            if ($profile->side === PermissionProfile::SIDE_AGENCY) {
                if (! self::isAgency($company)) {
                    throw ValidationException::withMessages(['profile_id' => ['Esta empresa não é uma agência.']]);
                }
                $before = $target->agency_profile_id;
                $target->forceFill(['agency_profile_id' => $profile->id])->save();
                PermissionProfileEvent::log('perfil_na_agencia_atribuido', $company->id, $profile->id, $target->id, ['antes' => $before, 'depois' => $profile->name]);

                return $target->fresh();
            }

            $isAdminProfile = $profile->system_key === PermissionProfile::ADMIN;
            if (! $isAdminProfile) {
                LastAdminGuard::assertKeeps($target, 'retirar o perfil de Administrador a');
            }
            $before = $target->profile?->name;
            $target->forceFill(['profile_id' => $profile->id, 'role' => $isAdminProfile ? 'admin' : 'user'])->save();
            PermissionProfileEvent::log('perfil_atribuido', $company->id, $profile->id, $target->id, ['antes' => $before, 'depois' => $profile->name]);

            return $target->fresh();
        });
    }

    /** O teto que o cliente dá à agência gestora (D2). */
    public function setCeiling(Company $company, PermissionProfile $profile): CompanyManagement
    {
        $m = CompanyManagement::active()->where('managed_company_id', $company->id)->first();
        if (! $m) {
            throw ValidationException::withMessages(['profile_id' => ['Esta empresa não tem uma agência gestora.']]);
        }
        if ($profile->side !== PermissionProfile::SIDE_CEILING || ! $this->usable($company, $profile)) {
            throw ValidationException::withMessages(['profile_id' => ['Escolha um perfil de teto para a agência.']]);
        }
        $before = $m->guestProfile?->name;
        $m->forceFill(['guest_profile_id' => $profile->id])->save();
        PermissionProfileEvent::log('teto_da_agencia', $company->id, $profile->id, null, ['agencia' => $m->agency_company_id, 'antes' => $before, 'depois' => $profile->name]);

        return $m->fresh();
    }

    /** Pode usar-se nesta empresa: de sistema (que não seja sugestão) ou da própria empresa. */
    public function usable(Company $company, PermissionProfile $profile): bool
    {
        return ($profile->company_id === null && $profile->is_system && ! $profile->is_suggestion) || (int) $profile->company_id === (int) $company->id;
    }

    private function assertEditable(Company $company, PermissionProfile $profile): void
    {
        if ($profile->is_system || (int) $profile->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['profile' => [$profile->system_key === PermissionProfile::ADMIN
                ? 'O perfil Administrador é de sistema e não se edita.'
                : 'Os perfis de sistema não se editam: crie um perfil a partir deste.']]);
        }
    }

    /** A faturação da XPLENDOR é só do Administrador: um perfil que a inclua não se grava. */
    public static function assertNoAdminOnly(array $permissions): void
    {
        $hit = array_values(array_filter($permissions, fn ($p) => is_string($p) && Permissions::isAdminOnly($p)));
        if ($hit !== []) {
            throw ValidationException::withMessages(['permissions' => ['A faturação da XPLENDOR é só do Administrador da empresa: não pode entrar noutro perfil.']]);
        }
    }

    private function assertUniqueName(Company $company, string $name, ?int $except = null): void
    {
        if (PermissionProfile::where('company_id', $company->id)->where('name', trim($name))->when($except, fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['name' => ['Já existe um perfil com este nome.']]);
        }
    }
}
