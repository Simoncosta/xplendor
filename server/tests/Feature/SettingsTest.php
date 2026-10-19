<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PermissionProfile;
use App\Models\PingwinCatalogItem;
use App\Models\SettingEvent;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use App\Settings\PingwinWriteGate;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Configurações: a prioridade (base de dados > .env > defeito), as permissões (administrador da
 * empresa e root), o travão global que ganha a tudo, o modo de testes, as listas antigas do .env e o
 * histórico (quem, quando, antes e depois).
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private int $c;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009930', 'fiscal_name' => 'Yuko Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->c = $this->company->id;
        app(CompanyModuleService::class)->applyPreset($this->c, 'restaurant');
        CompanyIntegration::create(['company_id' => $this->c, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);
        Settings::flush($this->c);
    }

    private function userWith(array $permissions, ?int $companyId = null): User
    {
        $p = PermissionProfile::create(['company_id' => $companyId ?? $this->c, 'side' => 'cliente', 'name' => 'P ' . implode(',', $permissions), 'is_system' => false, 'is_suggestion' => false]);
        $p->syncPermissions($permissions);

        return User::factory()->create(['company_id' => $companyId ?? $this->c, 'role' => 'user', 'profile_id' => $p->id]);
    }

    public function test_priority_database_then_env_then_default(): void
    {
        config(['menu_engineering.food_cost_target_pct' => null]);
        $this->assertSame(35.0, Settings::company($this->c, 'menu.food_cost_target_pct'));            // defeito
        config(['menu_engineering.food_cost_target_pct' => 32]);                                        // o .env (via config)
        $this->assertSame(32.0, Settings::company($this->c, 'menu.food_cost_target_pct'));
        $this->assertSame('env', Settings::source($this->c, 'menu.food_cost_target_pct'));
        Settings::setCompany($this->c, ['menu.food_cost_target_pct' => 30], null);                    // a base de dados
        $this->assertSame(30.0, Settings::company($this->c, 'menu.food_cost_target_pct'));
        $this->assertSame('base_de_dados', Settings::source($this->c, 'menu.food_cost_target_pct'));
        $this->assertSame(32.0, Settings::company(null, 'menu.food_cost_target_pct'));                  // sem empresa: o .env

        // A plataforma: sem a linha da migração, vale o .env.
        \App\Models\PlatformSetting::where('key', 'pingwin.proven.transfers')->delete();
        Settings::flush();
        config(['stock_transfers.send_proven' => true]);
        $this->assertTrue(Settings::platform('pingwin.proven.transfers'));
        Settings::setPlatform(['pingwin.proven.transfers' => false], null);
        $this->assertFalse(Settings::platform('pingwin.proven.transfers'));
    }

    public function test_history_records_who_when_before_and_after_and_only_real_changes(): void
    {
        $admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin', 'name' => 'Ana Admin']);
        $this->assertSame(['stock.recount_eur'], Settings::setCompany($this->c, ['stock.recount_eur' => 40, 'stock.recount_pct' => 20], $admin));
        $this->assertSame([], Settings::setCompany($this->c, ['stock.recount_eur' => 40], $admin));       // igual: sem histórico
        $e = SettingEvent::where('user_id', $admin->id)->sole();
        $this->assertSame(['stock.recount_eur', 25, 40, $admin->id], [$e->key, $e->before, $e->after, $e->user_id]);
        $this->assertNotNull($e->created_at);
        $this->assertSame('Ana Admin', Settings::history($this->c, ['empresa'])[0]['user']);
        $this->expectException(ValidationException::class);
        Settings::setCompany($this->c, ['stock.recount_pct' => -5], $admin);
    }

    public function test_company_screen_is_for_the_company_administrator_and_the_voids_rule_asks_for_consent(): void
    {
        $url = "/api/v1/companies/{$this->c}/settings/restaurant";
        $admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin']);
        $manager = $this->userWith(['restauracao.ver', 'restauracao.editar']);
        $configurer = $this->userWith(['restauracao.ver', 'restauracao.configurar']);   // não é o administrador
        $r = $this->actingAs($admin, 'sanctum')->getJson($url)->assertOk();
        $this->assertContains('audit.voids_by_employee', array_column($r->json('data.settings'), 'key'));
        $this->assertNotContains('pingwin.writes_enabled', array_column($r->json('data.settings'), 'key'));   // só root
        $this->actingAs($manager, 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs($manager, 'sanctum')->putJson($url, ['values' => ['stock.recount_eur' => 10]])->assertForbidden();

        $this->actingAs($admin, 'sanctum')->putJson($url, ['values' => ['menu.food_cost_target_pct' => 33, 'calendar.municipal_holiday' => '24/6']])->assertOk();
        $this->assertSame([33.0, '24/06'], [Settings::company($this->c, 'menu.food_cost_target_pct'), Settings::company($this->c, 'calendar.municipal_holiday')]);
        $this->actingAs($admin, 'sanctum')->putJson($url, ['values' => ['pingwin.writes_enabled' => false]])->assertStatus(422);   // a escrita é do root

        $this->assertFalse(Settings::company($this->c, 'audit.voids_by_employee'));   // desligada por defeito
        $this->actingAs($configurer, 'sanctum')->putJson($url, ['values' => ['stock.recount_eur' => 12]])->assertOk();   // a ACL (restauracao.configurar)
        $this->actingAs($configurer, 'sanctum')->putJson($url, ['values' => ['audit.voids_by_employee' => true], 'consent' => true])->assertStatus(422)
            ->assertJsonPath('message', 'Só o administrador da própria empresa liga as anulações por funcionário.');
        $this->actingAs($admin, 'sanctum')->putJson($url, ['values' => ['audit.voids_by_employee' => true]])->assertStatus(422)
            ->assertJsonPath('message', 'Ao ligar, o dono autoriza mostrar padrões por funcionário ao administrador.');
        $this->actingAs($admin, 'sanctum')->putJson($url, ['values' => ['audit.voids_by_employee' => true], 'consent' => true])->assertOk();
        $this->assertTrue(Settings::company($this->c, 'audit.voids_by_employee'));
        $this->assertSame('Ao ligar, o dono autoriza mostrar padrões por funcionário ao administrador.', SettingEvent::where('key', 'audit.voids_by_employee')->value('reason'));
    }

    public function test_pingwin_screen_is_root_only(): void
    {
        $admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin']);
        $root = User::factory()->create(['company_id' => $this->c, 'role' => 'root']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/integrations/pingwin')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/integrations/pingwin/companies/{$this->c}", ['values' => ['pingwin.writes_enabled' => false]])->assertForbidden();
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/integrations/pingwin/platform', ['values' => ['pingwin.proven.units' => true]])->assertForbidden();

        $this->actingAs($root, 'sanctum')->putJson("/api/v1/admin/integrations/pingwin/companies/{$this->c}", ['values' => ['pingwin.writes_enabled' => false]])->assertOk();
        $this->actingAs($root, 'sanctum')->putJson('/api/v1/admin/integrations/pingwin/platform', ['values' => ['pingwin.proven.units' => true]])->assertOk();
        $this->actingAs($root, 'sanctum')->putJson('/api/v1/admin/integrations/pingwin/platform', ['values' => ['menu.food_cost_target_pct' => 20]])->assertStatus(422);
        $d = $this->actingAs($root, 'sanctum')->getJson('/api/v1/admin/integrations/pingwin')->assertOk()->json('data');
        $this->assertTrue($d['global_writes_enabled']);
        $this->assertSame('desligada', $d['companies'][0]['block']['mode']);
        // O histórico: as duas mudanças do root (as outras linhas são da migração, sem pessoa).
        $mine = array_values(array_filter($d['history'], fn ($h) => $h['user'] !== null));
        $this->assertEqualsCanonicalizing(['pingwin.writes_enabled', 'pingwin.proven.units'], array_column($mine, 'key'));
    }

    public function test_global_brake_wins_over_everything(): void
    {
        Settings::setCompany($this->c, ['pingwin.writes_enabled' => true, 'pingwin.test_mode' => false], null);
        $this->assertNull(PingwinService::bomWriteGuard('P1', $this->c));
        config(['services.pingwin.writes_enabled' => false]);
        $this->assertSame('desligada', PingwinService::bomWriteGuard('P1', $this->c)['mode']);
        $this->assertSame('desligada', PingwinService::stockDocWriteGuard(['101'], $this->c)['mode']);
        $this->assertSame(['0'], PingwinService::unitWriteAllowlist($this->c));
        $this->assertSame('desligada', PingwinService::bomWriteGuard('P1')['mode']);   // também sem empresa (listas antigas)
        try {
            app(PingwinService::class)->writeBom($this->c, 'P1', []);
            $this->fail('devia recusar');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('PINGWIN_WRITES_ENABLED=false', $e->errors()['pingwin'][0]);
        }
    }

    public function test_a_write_that_skips_the_company_gate_never_reaches_the_pingwin(): void
    {
        $svc = app(PingwinService::class);
        $invoke = new \ReflectionMethod($svc, 'invoke');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sem passar pelo travão da empresa');
        $invoke->invoke($svc, ['mode' => 'bom_write']);
    }

    public function test_company_brake_and_test_mode_replace_the_env_lists(): void
    {
        foreach ([['T1', '101335', 'TESTE XPLENDOR FICHA'], ['P2', '200', 'Francesinha']] as [$id, $code, $d]) {
            PingwinCatalogItem::create(['company_id' => $this->c, 'pingwin_id' => $id, 'code' => $code, 'description' => $d, 'is_active' => true]);
        }
        // Sem definições gravadas: mandam as listas do .env (como antes).
        config(['services.pingwin.bom_write_allowlist' => '0']);
        $this->assertTrue(PingwinWriteGate::legacy($this->c));
        $this->assertSame('desligada', PingwinService::bomWriteGuard('T1', $this->c)['mode']);

        // Modo de testes: só os "TESTE XPLENDOR", em vez das listas.
        Settings::setCompany($this->c, ['pingwin.writes_enabled' => true, 'pingwin.test_mode' => true], null);
        $this->assertSame(['T1'], PingwinService::bomWriteAllowlist($this->c));
        $this->assertSame(['101335'], PingwinService::unitWriteAllowlist($this->c));
        $this->assertNull(PingwinService::bomWriteGuard('T1', $this->c));
        $this->assertSame('teste', PingwinService::bomWriteGuard('P2', $this->c)['mode']);
        $this->assertSame('teste', PingwinService::stockDocWriteGuard(['101335', '200'], $this->c)['mode']);
        $this->assertNull(PingwinService::stockDocWriteGuard(['101335'], $this->c));
        $this->assertSame('teste', PingwinWriteGate::blockOther($this->c)['mode']);                 // fornecedores, documentos…
        $this->assertNull(PingwinWriteGate::blockArticle($this->c, 'TESTE XPLENDOR NOVO'));
        $this->assertSame('teste', PingwinWriteGate::blockArticle($this->c, 'Bife')['mode']);

        // Sem modo de testes: todos.
        Settings::setCompany($this->c, ['pingwin.test_mode' => false], null);
        $this->assertSame([], PingwinService::bomWriteAllowlist($this->c));
        $this->assertNull(PingwinService::bomWriteGuard('P2', $this->c));

        // Travão da empresa: nada.
        Settings::setCompany($this->c, ['pingwin.writes_enabled' => false], null);
        $this->assertSame('desligada', PingwinService::bomWriteGuard('T1', $this->c)['mode']);
        $this->assertSame('desligada', PingwinWriteGate::blockArticle($this->c, 'TESTE XPLENDOR X')['mode']);
        try {
            app(PingwinService::class)->createSupplier($this->c, ['name' => 'X']);
            $this->fail('devia recusar');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('desligada para esta empresa', $e->errors()['pingwin'][0]);
        }
    }

    public function test_company_settings_reach_the_readers(): void
    {
        Settings::setCompany($this->c, ['menu.food_cost_target_pct' => 28, 'menu.family_targets' => ['Bebidas' => 22],
            'audit.money_severity_high' => 50, 'audit.money_severity_medium' => 5], null);
        $this->assertSame(28.0, \App\Audit\Rules\Restaurant\Menu\FoodCostAcimaAlvoRule::targetFor('Comidas › Pratos', $this->c));
        $this->assertSame(22.0, \App\Audit\Rules\Restaurant\Menu\FoodCostAcimaAlvoRule::targetFor('Bebidas', $this->c));
        new \App\Audit\AuditScope($this->c, 'restauracao', 'central');
        $this->assertSame(['alta', 'media', 'baixa'], [\App\Audit\AuditScope::severityFor(60, 'money'), \App\Audit\AuditScope::severityFor(10, 'money'), \App\Audit\AuditScope::severityFor(4, 'money')]);
        $this->assertSame('alta', \App\Audit\AuditScope::severityFor(150, 'units'));
        $this->assertSame('media', \App\Audit\AuditScope::severityFor(60, 'units'));
    }
}
