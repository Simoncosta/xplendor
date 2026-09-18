<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Fase 3: as ROTAS recusam (403) se o módulo não está ativo (não só
 * escondido no menu). Empresa "restauração" (só transversais) não acede aos
 * endpoints de carros; empresa automotiva acede; transversais/base sempre; root tudo.
 */
class ModuleRouteGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $auto;
    private Company $resto;
    private User $autoUser;
    private User $restoUser;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Ambas nascem automotivas (observer). A "resto" passa a só-transversais.
        $this->auto = Company::create(['nipc' => '500001700', 'fiscal_name' => 'Auto Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->resto = Company::create(['nipc' => '500001701', 'fiscal_name' => 'Resto Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');

        $this->autoUser = User::factory()->create(['company_id' => $this->auto->id, 'role' => 'admin']);
        $this->restoUser = User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);
        $this->root = User::factory()->create(['company_id' => $this->auto->id, 'role' => 'root']);
    }

    private function url(int $companyId, string $path): string
    {
        return "/api/v1/companies/{$companyId}/{$path}";
    }

    public function test_restaurant_is_blocked_from_car_module_endpoints(): void
    {
        foreach (['cars', 'leads', 'customers', 'document-templates', 'expenses', 'expense-categories', 'suppliers', 'stock/promotion-candidates'] as $path) {
            $this->actingAs($this->restoUser, 'sanctum')
                ->getJson($this->url($this->resto->id, $path))
                ->assertStatus(403);
        }
    }

    public function test_automotive_company_can_access_car_endpoints(): void
    {
        // Passa o middleware de módulo (não 403). O controller responde normalmente.
        $this->actingAs($this->autoUser, 'sanctum')
            ->getJson($this->url($this->auto->id, 'cars'))
            ->assertStatus(200);
        $this->actingAs($this->autoUser, 'sanctum')
            ->getJson($this->url($this->auto->id, 'customers'))
            ->assertStatus(200);
    }

    public function test_transversal_and_base_endpoints_always_accessible(): void
    {
        // Restauração (sem módulos de carros) acede na mesma aos transversais/base.
        $this->actingAs($this->restoUser, 'sanctum')->getJson($this->url($this->resto->id, 'my-modules'))->assertStatus(200);
        $this->actingAs($this->restoUser, 'sanctum')->getJson($this->url($this->resto->id, 'tasks'))->assertStatus(200);
        $this->actingAs($this->restoUser, 'sanctum')->getJson($this->url($this->resto->id, 'support-tickets'))->assertStatus(200);
        $this->actingAs($this->restoUser, 'sanctum')->getJson($this->url($this->resto->id, 'quotes'))->assertStatus(200);
    }

    public function test_root_bypasses_module_gate(): void
    {
        // Root acede aos endpoints de carros mesmo numa empresa sem o módulo.
        $this->actingAs($this->root, 'sanctum')
            ->getJson($this->url($this->resto->id, 'cars'))
            ->assertStatus(200);
    }

    public function test_reenabling_module_restores_access(): void
    {
        // Liga o módulo stock na resto → passa a aceder (coerência com a gestão).
        app(CompanyModuleService::class)->enable($this->resto->id, 'stock');
        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson($this->url($this->resto->id, 'cars'))
            ->assertStatus(200);
    }
}
