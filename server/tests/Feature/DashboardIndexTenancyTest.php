<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SEGURANÇA — tenancy do GET /companies/{id}/dashboard (DashboardController::index).
 *
 * O index devolve o blob do dashboard (resumo, viaturas, leads, marketing…) da
 * empresa da ROTA. Tem de aplicar o mesmo guard dos outros dois endpoints do
 * dashboard (stockBreakdown/salesRevenue): o utilizador só vê a SUA empresa; root
 * (sem impersonation) vê qualquer uma; em impersonation o token é do utilizador-alvo,
 * por isso só vê a empresa desse utilizador.
 */
class DashboardIndexTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private User $adminA;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->companyA = Company::create(['nipc' => '500003001', 'fiscal_name' => 'Empresa A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->companyB = Company::create(['nipc' => '500003002', 'fiscal_name' => 'Empresa B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->adminA = User::factory()->create(['company_id' => $this->companyA->id, 'role' => 'admin']);
        $this->adminB = User::factory()->create(['company_id' => $this->companyB->id, 'role' => 'admin']);
    }

    /**
     * O DashboardService usa SQL de MySQL (DATEDIFF/NOW) que não corre em sqlite;
     * aqui interessa só a camada de AUTORIZAÇÃO do controller, por isso o service é
     * substituído por um duplo que devolve um marcador com a empresa pedida.
     */
    private function fakeService(bool $mustNotBeCalled = false): void
    {
        $this->mock(DashboardService::class, function ($m) use ($mustNotBeCalled) {
            if ($mustNotBeCalled) {
                $m->shouldNotReceive('getDashboard');
            } else {
                $m->shouldReceive('getDashboard')->andReturnUsing(fn (int $companyId) => ['company_marker' => $companyId]);
            }
        });
    }

    private function url(int $companyId): string
    {
        return "/api/v1/companies/{$companyId}/dashboard";
    }

    public function test_user_of_a_cannot_read_dashboard_of_b(): void
    {
        $this->fakeService(mustNotBeCalled: true); // nem sequer calcula os dados de B
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->url($this->companyB->id))
            ->assertStatus(403);
    }

    public function test_user_of_a_reads_own_dashboard(): void
    {
        $this->fakeService();
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->url($this->companyA->id))
            ->assertStatus(200)
            ->assertJsonPath('data.company_marker', $this->companyA->id);
    }

    public function test_impersonation_token_of_b_only_sees_b(): void
    {
        $this->fakeService();
        // O token de impersonation é emitido PARA o utilizador-alvo (ability 'impersonation').
        Sanctum::actingAs($this->adminB, ['impersonation']);

        $this->getJson($this->url($this->companyB->id))->assertStatus(200);
        $this->getJson($this->url($this->companyA->id))->assertStatus(403);
    }

    public function test_root_without_impersonation_reads_any_company(): void
    {
        $this->fakeService();
        $root = User::factory()->create(['company_id' => $this->companyA->id, 'role' => 'root']);

        $this->actingAs($root, 'sanctum')->getJson($this->url($this->companyA->id))->assertStatus(200);
        $this->actingAs($root, 'sanctum')->getJson($this->url($this->companyB->id))->assertStatus(200);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson($this->url($this->companyA->id))->assertStatus(401);
    }
}
