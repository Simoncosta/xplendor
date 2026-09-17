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
 * XPLENDOR — Stock GLOBAL (transversal, /admin, só root). Cobre: ver veículos de
 * VÁRIAS empresas, EXCLUIR empresas inativas (subscrição expirada/cancelada,
 * trial expirado, arquivadas), e o bloqueio de não-root.
 */
class AdminStockTest extends TestCase
{
    use RefreshDatabase;

    private User $root;
    private User $standAdmin;
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
        $this->standAdmin = User::factory()->create(['company_id' => $home->id, 'role' => 'admin']);
    }

    private function company(string $name, string $subscription, $trialEnds = null, bool $trashed = false): Company
    {
        $c = Company::create([
            'nipc' => (string) random_int(500000000, 599999999),
            'fiscal_name' => $name,
            'plan_id' => $this->planId,
            'subscription_status' => $subscription,
            'trial_ends_at' => $trialEnds,
        ]);
        if ($trashed) {
            $c->delete(); // soft-delete (arquivada)
        }
        return $c;
    }

    private function carFor(Company $c, string $status = 'active'): Car
    {
        return Car::factory()->create(['company_id' => $c->id, 'status' => $status]);
    }

    public function test_root_sees_cars_from_multiple_active_companies(): void
    {
        $a = $this->company('Ativa A', 'active');
        $b = $this->company('Trial válida', 'trial', now()->addDays(5));
        $this->carFor($a);
        $this->carFor($b);

        $res = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/stock');
        $res->assertStatus(200);
        $companies = collect($res->json('data.data'))->pluck('company_name');
        $this->assertTrue($companies->contains('Ativa A'));
        $this->assertTrue($companies->contains('Trial válida'));
        // Cada carro traz a empresa a que pertence.
        $this->assertNotNull($res->json('data.data.0.company_name'));
    }

    public function test_inactive_companies_are_excluded(): void
    {
        $expired = $this->company('Expirada', 'expired');
        $cancelled = $this->company('Cancelada', 'cancelled');
        $trialGone = $this->company('Trial expirado', 'trial', now()->subDay());
        $archived = $this->company('Arquivada', 'active', null, true);
        $this->carFor($expired);
        $this->carFor($cancelled);
        $this->carFor($trialGone);
        $this->carFor($archived);

        $names = collect(
            $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/stock')->json('data.data')
        )->pluck('company_name');

        $this->assertFalse($names->contains('Expirada'));
        $this->assertFalse($names->contains('Cancelada'));
        $this->assertFalse($names->contains('Trial expirado'));
        $this->assertFalse($names->contains('Arquivada'));
        $this->assertCount(0, $names);
    }

    public function test_filter_by_company_and_status(): void
    {
        $a = $this->company('Filtro A', 'active');
        $b = $this->company('Filtro B', 'active');
        $this->carFor($a, 'active');
        $this->carFor($a, 'sold');
        $this->carFor($b, 'active');

        $onlyA = collect($this->actingAs($this->root, 'sanctum')
            ->getJson("/api/v1/admin/stock?company_id={$a->id}")->json('data.data'));
        $this->assertCount(2, $onlyA);

        $sold = collect($this->actingAs($this->root, 'sanctum')
            ->getJson('/api/v1/admin/stock?status=sold')->json('data.data'));
        $this->assertCount(1, $sold);
        $this->assertSame('sold', $sold->first()['status']);
    }

    public function test_summary_counts_only_active_companies(): void
    {
        $a = $this->company('Sum A', 'active');
        $dead = $this->company('Sum Morta', 'expired');
        $this->carFor($a, 'active');
        $this->carFor($a, 'sold');
        $this->carFor($dead, 'active'); // não conta

        $res = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/stock/summary');
        $res->assertStatus(200)
            ->assertJsonPath('data.total_vehicles', 2)
            ->assertJsonPath('data.by_status.active', 1)
            ->assertJsonPath('data.by_status.sold', 1);
    }

    public function test_companies_dropdown_lists_only_active_with_stock(): void
    {
        $withStock = $this->company('Com Stock', 'active');
        $noStock = $this->company('Sem Stock', 'active');
        $dead = $this->company('Morta com stock', 'cancelled');
        $this->carFor($withStock);
        $this->carFor($dead);

        $names = collect($this->actingAs($this->root, 'sanctum')
            ->getJson('/api/v1/admin/stock/companies')->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Com Stock'));
        $this->assertFalse($names->contains('Sem Stock'));   // sem veículos
        $this->assertFalse($names->contains('Morta com stock')); // inativa
    }

    public function test_non_root_forbidden_on_all_admin_stock_endpoints(): void
    {
        $this->actingAs($this->standAdmin, 'sanctum')->getJson('/api/v1/admin/stock')->assertStatus(403);
        $this->actingAs($this->standAdmin, 'sanctum')->getJson('/api/v1/admin/stock/summary')->assertStatus(403);
        $this->actingAs($this->standAdmin, 'sanctum')->getJson('/api/v1/admin/stock/companies')->assertStatus(403);
    }

    public function test_stand_scoped_cars_endpoint_still_works(): void
    {
        // O endpoint de stand continua scoped e intacto (não afetado por esta vista).
        $home = $this->standAdmin->company_id;
        $this->carFor(Company::find($home));
        $this->actingAs($this->standAdmin, 'sanctum')
            ->getJson("/api/v1/companies/{$home}/cars")
            ->assertStatus(200);
    }
}
