<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Access\CompatibilityProfiles;
use App\Access\Permissions;
use App\Access\RoutePermissions;
use Illuminate\Routing\Route as RouteDef;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ACL, estático: TODAS as rotas de empresa têm o portão permission e uma permissão
 * declarada que existe no catálogo; o catálogo não tem chaves sem rota; os perfis de
 * compatibilidade gravados são os que se derivam da fotografia (não escritos à mão).
 */
class AccessCatalogTest extends TestCase
{
    public function test_every_company_route_has_the_permission_gate_and_a_declared_permission(): void
    {
        $missing = [];
        $invalid = [];
        $noGate = [];
        foreach (AccessSnapshotTest::companyRoutes() as $route) {
            $key = RoutePermissions::keyFor($route);
            $permission = RoutePermissions::MAP[$key] ?? null;
            if ($permission === null) {
                $missing[] = $key;
            } elseif (! Permissions::isValid($permission)) {
                $invalid[] = "{$key} → {$permission}";
            }
            if (! collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && ($m === 'permission' || str_starts_with($m, 'permission:')))) {
                $noGate[] = $key;
            }
        }
        $this->assertSame([], $missing, 'Rota de empresa sem permissão em app/Access/RoutePermissions.php.');
        $this->assertSame([], $invalid, 'Permissão que não existe em app/Access/Permissions.php.');
        $this->assertSame([], $noGate, 'Rota de empresa sem o middleware permission.');
    }

    public function test_the_catalog_has_no_key_without_a_route(): void
    {
        $keys = array_map(fn (RouteDef $r) => RoutePermissions::keyFor($r), AccessSnapshotTest::companyRoutes());
        $this->assertSame([], array_values(array_diff(array_keys(RoutePermissions::MAP), $keys)));
    }

    public function test_the_compatibility_profiles_are_derived_from_the_snapshot(): void
    {
        $this->assertSame(0, Artisan::call('acl:derive', ['--check' => true]), Artisan::output());
        $derived = CompatibilityProfiles::derive(CompatibilityProfiles::snapshots(), RoutePermissions::MAP);
        $this->assertSame([], $derived['conflitos']);
    }

    public function test_no_profile_ever_gets_the_platform_area(): void
    {
        foreach (CompatibilityProfiles::load()['perfis'] as $key => $profile) {
            foreach (array_keys(CompatibilityProfiles::allowed($key)) as $p) {
                $this->assertNotSame('plataforma', Permissions::area($p), "{$key} tem {$p}");
            }
        }
    }
}
