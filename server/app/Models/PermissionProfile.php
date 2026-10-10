<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ACL: um perfil de permissão (documents/ACL-DESENHO.md). company_id nulo = perfil de
 * sistema (os de compatibilidade, o Administrador e as sugestões); os personalizados são de
 * uma empresa. As permissões estão em profile_permissions (área × ação).
 */
class PermissionProfile extends Model
{
    public const SIDE_CLIENT = 'cliente';
    public const SIDE_AGENCY = 'agencia';
    public const SIDE_CEILING = 'teto';

    /** Perfis de sistema (chave → perfil de compatibilidade de onde vêm as permissões). */
    public const ADMIN = 'administrador';
    public const USER_COMPAT = 'utilizador_como_hoje';
    public const AGENCY_ADMIN_COMPAT = 'agencia_admin_como_hoje';
    public const AGENCY_MEMBER_COMPAT = 'gestor_clientes_como_hoje';
    public const CEILING_COMPAT = 'teto_agencia_como_hoje';

    protected $fillable = ['company_id', 'side', 'system_key', 'name', 'description', 'is_system', 'is_suggestion', 'created_by_user_id'];

    protected $casts = ['is_system' => 'boolean', 'is_suggestion' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(ProfilePermission::class, 'profile_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'profile_id');
    }

    public static function system(string $key): ?self
    {
        return static::where('system_key', $key)->first();
    }

    /** @return string[] "area.acao" */
    public function permissionKeys(): array
    {
        return $this->permissions()->orderBy('area')->orderBy('action')->get()->map(fn ($p) => "{$p->area}.{$p->action}")->all();
    }

    /** Substitui as permissões do perfil (as inválidas e as da plataforma são ignoradas). @param string[] $permissions */
    public function syncPermissions(array $permissions): void
    {
        $valid = array_values(array_unique(array_intersect($permissions, \App\Access\Permissions::assignable())));
        $this->permissions()->delete();
        foreach ($valid as $p) {
            [$area, $action] = explode('.', $p, 2);
            $this->permissions()->create(['area' => $area, 'action' => $action]);
        }
    }
}
