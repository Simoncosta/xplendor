<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Módulos por empresa (Incremento 1: estrutura). Cobre: default
 * automotivo, teia de dependências (bloquear desligar com dependentes ativos — a
 * cadeia), ligar em cascata, presets de ramo, ajuste individual, e só-root.
 */
class CompanyModulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $root;
    private User $standAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Company::create dispara o observer → preset automotivo por defeito.
        $this->company = Company::create(['nipc' => '500001500', 'fiscal_name' => 'Stand A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $this->company->id, 'role' => 'root']);
        $this->standAdmin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    private function modulesUrl(?int $id = null): string
    {
        return '/api/v1/admin/companies/' . ($id ?? $this->company->id) . '/modules';
    }

    private function keys(int $companyId): array
    {
        return CompanyModule::where('company_id', $companyId)->pluck('module_key')->sort()->values()->all();
    }

    public function test_new_company_defaults_to_automotive_preset(): void
    {
        // O observer ligou todos os módulos automotivos (para não perder acesso).
        $this->assertEqualsCanonicalizing(ModuleRegistry::presetKeys('automotive'), $this->keys($this->company->id));
        $this->assertCount(7, $this->keys($this->company->id));
    }

    public function test_root_sees_modules_overview(): void
    {
        $res = $this->actingAs($this->root, 'sanctum')->getJson($this->modulesUrl())->assertStatus(200);
        $this->assertCount(count(ModuleRegistry::keys()), $res->json('data.modules'));
        // stock está ligado mas NÃO pode desligar (comercial + pós-venda dependem).
        $stock = collect($res->json('data.modules'))->firstWhere('key', 'stock');
        $this->assertTrue($stock['enabled']);
        $this->assertFalse($stock['can_disable']);
        $this->assertNotEmpty($stock['blocking_dependents']);
    }

    public function test_cannot_disable_module_with_active_dependents_chain(): void
    {
        // Tudo ligado. Desligar 'stock' → bloqueado (commercial_crm E aftersales dependem — cadeia).
        $this->actingAs($this->root, 'sanctum')
            ->patchJson($this->modulesUrl(), ['module_key' => 'stock', 'enabled' => false])
            ->assertStatus(422);
        $this->assertContains('stock', $this->keys($this->company->id)); // continua ligado

        // Desligar 'commercial_crm' também → bloqueado (aftersales depende).
        $this->actingAs($this->root, 'sanctum')
            ->patchJson($this->modulesUrl(), ['module_key' => 'commercial_crm', 'enabled' => false])
            ->assertStatus(422);
    }

    public function test_disable_in_correct_order_works(): void
    {
        // Desligar o topo da cadeia primeiro (aftersales) → depois commercial_crm → depois stock.
        $this->actingAs($this->root, 'sanctum')->patchJson($this->modulesUrl(), ['module_key' => 'aftersales', 'enabled' => false])->assertStatus(200);
        $this->actingAs($this->root, 'sanctum')->patchJson($this->modulesUrl(), ['module_key' => 'commercial_crm', 'enabled' => false])->assertStatus(200);
        $this->actingAs($this->root, 'sanctum')->patchJson($this->modulesUrl(), ['module_key' => 'stock', 'enabled' => false])->assertStatus(200);

        $this->assertNotContains('stock', $this->keys($this->company->id));
    }

    public function test_enabling_a_module_cascades_dependencies(): void
    {
        // Parte do preset restauração (só transversais).
        $this->actingAs($this->root, 'sanctum')->postJson($this->modulesUrl() . '/preset', ['preset' => 'restaurant'])->assertStatus(200);
        $this->assertEqualsCanonicalizing(['marketing_analytics', 'support_tasks', 'pingwin'], $this->keys($this->company->id));

        // Ligar 'aftersales' → liga em cascata commercial_crm + stock.
        $this->actingAs($this->root, 'sanctum')->patchJson($this->modulesUrl(), ['module_key' => 'aftersales', 'enabled' => true])->assertStatus(200);
        $keys = $this->keys($this->company->id);
        $this->assertContains('aftersales', $keys);
        $this->assertContains('commercial_crm', $keys);
        $this->assertContains('stock', $keys);
    }

    public function test_restaurant_preset_enables_only_transversal(): void
    {
        $this->actingAs($this->root, 'sanctum')->postJson($this->modulesUrl() . '/preset', ['preset' => 'restaurant'])->assertStatus(200);
        $this->assertEqualsCanonicalizing(['marketing_analytics', 'support_tasks', 'pingwin'], $this->keys($this->company->id));
    }

    public function test_preset_is_a_shortcut_not_a_prison(): void
    {
        // Aplico restauração e depois LIGO um módulo individualmente.
        $this->actingAs($this->root, 'sanctum')->postJson($this->modulesUrl() . '/preset', ['preset' => 'restaurant'])->assertStatus(200);
        $this->actingAs($this->root, 'sanctum')->patchJson($this->modulesUrl(), ['module_key' => 'finance', 'enabled' => true])->assertStatus(200);
        $this->assertContains('finance', $this->keys($this->company->id));
    }

    public function test_only_root_can_manage_modules(): void
    {
        $this->actingAs($this->standAdmin, 'sanctum')->getJson($this->modulesUrl())->assertStatus(403);
        $this->actingAs($this->standAdmin, 'sanctum')->patchJson($this->modulesUrl(), ['module_key' => 'finance', 'enabled' => false])->assertStatus(403);
    }

    // ── Fase 2: endpoint /my-modules (o frontend lê para esconder) ────────────

    private function myModulesUrl(?int $companyId = null): string
    {
        return '/api/v1/companies/' . ($companyId ?? $this->company->id) . '/my-modules';
    }

    public function test_stand_reads_its_own_active_modules(): void
    {
        // Empresa nova = automotivo (7). O admin da empresa vê os seus módulos.
        $res = $this->actingAs($this->standAdmin, 'sanctum')->getJson($this->myModulesUrl())->assertStatus(200);
        $this->assertEqualsCanonicalizing(ModuleRegistry::presetKeys('automotive'), $res->json('data.modules'));
    }

    public function test_my_modules_reflects_restaurant_preset(): void
    {
        // Simula uma empresa "restauração" (só transversais).
        app(\App\Services\CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');

        $res = $this->actingAs($this->standAdmin, 'sanctum')->getJson($this->myModulesUrl())->assertStatus(200);
        $this->assertEqualsCanonicalizing(['marketing_analytics', 'support_tasks', 'pingwin'], $res->json('data.modules'));
        // NÃO tem os módulos de carros → o menu esconde essas secções.
        $this->assertNotContains('stock', $res->json('data.modules'));
        $this->assertNotContains('commercial_crm', $res->json('data.modules'));
        $this->assertNotContains('finance', $res->json('data.modules'));
    }

    public function test_my_modules_tenancy(): void
    {
        $planId = DB::table('plans')->where('name', 'P')->value('id');
        $other = Company::create(['nipc' => '500001599', 'fiscal_name' => 'Stand B', 'plan_id' => $planId, 'subscription_status' => 'active']);

        // Utilizador da empresa A não vê os módulos da empresa B.
        $this->actingAs($this->standAdmin, 'sanctum')->getJson($this->myModulesUrl($other->id))->assertStatus(403);
    }
}
