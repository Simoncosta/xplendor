<?php

declare(strict_types=1);

namespace App\Audit;

use App\Access\Access;
use App\Models\User;

/**
 * XPLENDOR — Auditoria: as áreas e as permissões que já existem (sem área nova na ACL). Ver um
 * achado pede a permissão de ver da sua área; mudar o estado pede a de editar. O módulo da área tem
 * de estar ligado (o root não é filtrado por módulos, como no resto da plataforma).
 */
final class AuditAreas
{
    public const AREAS = [
        'restauracao' => ['label' => 'Restauração', 'ver' => 'restauracao.ver', 'editar' => 'restauracao.editar', 'modules' => ['pingwin']],
        'automovel' => ['label' => 'Automóvel', 'ver' => 'automovel.ver', 'editar' => 'automovel.editar', 'modules' => ['stock']],
        'tracking' => ['label' => 'Tracking dos sites', 'ver' => 'resultados.ver', 'editar' => 'integracoes.configurar', 'modules' => ['marketing_analytics']],
    ];

    public static function exists(string $area): bool
    {
        return isset(self::AREAS[$area]);
    }

    public static function label(string $area): string
    {
        return self::AREAS[$area]['label'] ?? $area;
    }

    public static function canSee(?User $user, int $companyId, string $area): bool
    {
        return self::allowed($user, $companyId, $area, 'ver');
    }

    public static function canEdit(?User $user, int $companyId, string $area): bool
    {
        return self::allowed($user, $companyId, $area, 'editar');
    }

    /** @return list<string> as áreas com regras que a pessoa pode ver nesta empresa */
    public static function visible(?User $user, int $companyId): array
    {
        return array_values(array_filter(AuditRegistry::areas(), fn (string $a) => self::canSee($user, $companyId, $a)));
    }

    /**
     * Auditoria v2-C · as regras sensíveis (com nomes de funcionários, C3): só o administrador da
     * própria empresa e o dono da plataforma as veem.
     */
    public static function seesSensitive(?User $user, int $companyId): bool
    {
        if (! $user) {
            return false;
        }

        return $user->isRoot() || ((int) $user->company_id === $companyId && app(Access::class)->isCompanyAdmin($user));
    }

    /** @return list<string> os códigos das regras que esta pessoa não pode ver */
    public static function hiddenRules(?User $user, int $companyId): array
    {
        return self::seesSensitive($user, $companyId) ? [] : AuditRegistry::sensitiveCodes();
    }

    private static function allowed(?User $user, int $companyId, string $area, string $action): bool
    {
        $def = self::AREAS[$area] ?? null;
        if (! $user || ! $def) {
            return false;
        }

        return app(Access::class)->can($user, $companyId, $def[$action], ['modules' => $def['modules']])->allowed;
    }
}
