<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Ativar/inativar empresa (root, /admin). Cobre: mudar estado,
 * perda de acesso (CheckCompanySubscription), exclusão do Stock Global
 * (Company::scopeActive), reativar, e bloqueio de não-root. Critério único:
 * hasPlatformAccess() = is_active = scopeActive = guard de acesso.
 */
class AdminCompanyStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $root;
    private User $standAdmin;
    private Company $target;
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $home = $this->company('Empresa Root', 'active');
        $this->root = User::factory()->create(['company_id' => $home->id, 'role' => 'root']);

        $this->target = $this->company('Spacedrive', 'active');
        $this->standAdmin = User::factory()->create(['company_id' => $this->target->id, 'role' => 'admin']);
    }

    private function company(string $name, string $subscription): Company
    {
        return Company::create([
            'nipc' => (string) random_int(500000000, 599999999),
            'fiscal_name' => $name,
            'plan_id' => $this->planId,
            'subscription_status' => $subscription,
        ]);
    }

    public function test_root_deactivates_company_changes_state(): void
    {
        $this->assertTrue($this->target->fresh()->is_active);

        $res = $this->actingAs($this->root, 'sanctum')
            ->patchJson("/api/v1/admin/companies/{$this->target->id}/status", ['active' => false]);
        $res->assertStatus(200);

        $fresh = $this->target->fresh();
        $this->assertSame(Company::SUBSCRIPTION_STATUS_CANCELLED, $fresh->subscription_status);
        $this->assertFalse($fresh->is_active); // critério único
    }

    public function test_deactivating_removes_company_from_global_stock(): void
    {
        Car::factory()->create(['company_id' => $this->target->id, 'status' => 'active']);

        // Antes: aparece no stock global.
        $before = collect($this->actingAs($this->root, 'sanctum')
            ->getJson('/api/v1/admin/stock')->json('data.data'))->pluck('company_name');
        $this->assertTrue($before->contains('Spacedrive'));

        // Inativar.
        $this->actingAs($this->root, 'sanctum')
            ->patchJson("/api/v1/admin/companies/{$this->target->id}/status", ['active' => false])
            ->assertStatus(200);

        // Depois: sai do stock global.
        $after = collect($this->actingAs($this->root, 'sanctum')
            ->getJson('/api/v1/admin/stock')->json('data.data'))->pluck('company_name');
        $this->assertFalse($after->contains('Spacedrive'));
    }

    public function test_deactivating_blocks_company_users_access(): void
    {
        // Instância fresca por pedido — cada request HTTP em produção carrega o
        // utilizador de novo; reutilizar a mesma instância cacheava a relação
        // company e mascarava a mudança de estado.
        $freshStand = fn () => User::find($this->standAdmin->id);

        // Utilizador da empresa acede antes (rota atrás do check_company_subscription).
        $this->actingAs($freshStand(), 'sanctum')
            ->getJson("/api/v1/companies/{$this->target->id}/cars")
            ->assertStatus(200);

        $this->actingAs($this->root, 'sanctum')
            ->patchJson("/api/v1/admin/companies/{$this->target->id}/status", ['active' => false])
            ->assertStatus(200);

        // Depois de inativar → 403 (perde acesso à plataforma).
        $this->actingAs($freshStand(), 'sanctum')
            ->getJson("/api/v1/companies/{$this->target->id}/cars")
            ->assertStatus(403);
    }

    public function test_root_reactivates_restores_access(): void
    {
        $this->actingAs($this->root, 'sanctum')
            ->patchJson("/api/v1/admin/companies/{$this->target->id}/status", ['active' => false])
            ->assertStatus(200);
        $this->assertFalse($this->target->fresh()->is_active);

        $this->actingAs($this->root, 'sanctum')
            ->patchJson("/api/v1/admin/companies/{$this->target->id}/status", ['active' => true])
            ->assertStatus(200);

        $fresh = $this->target->fresh();
        $this->assertSame(Company::SUBSCRIPTION_STATUS_ACTIVE, $fresh->subscription_status);
        $this->assertTrue($fresh->is_active);

        // Acesso reposto.
        $this->actingAs($this->standAdmin, 'sanctum')
            ->getJson("/api/v1/companies/{$this->target->id}/cars")
            ->assertStatus(200);
    }

    public function test_non_root_cannot_change_company_status(): void
    {
        $this->actingAs($this->standAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/companies/{$this->target->id}/status", ['active' => false])
            ->assertStatus(403);
        // Estado inalterado.
        $this->assertTrue($this->target->fresh()->is_active);
    }

    public function test_company_payload_exposes_is_active(): void
    {
        // A lista de empresas (root) traz is_active — rótulo consistente com a exclusão.
        $data = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/companies')->json('data');
        $row = collect($data)->firstWhere('fiscal_name', 'Spacedrive');
        $this->assertNotNull($row);
        $this->assertArrayHasKey('is_active', $row);
        $this->assertTrue($row['is_active']);
    }
}
