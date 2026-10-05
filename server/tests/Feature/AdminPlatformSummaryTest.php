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
 * Dashboard root, cartão "Carros": conta só as viaturas EM STOCK (Car::IN_STOCK_STATUSES)
 * de empresas ATIVAS (Company::active: subscrição ativa ou trial dentro do prazo). O total
 * geral continua disponível como referência.
 */
class AdminPlatformSummaryTest extends TestCase
{
    use RefreshDatabase;

    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function company(string $nipc, array $extra): Company
    {
        return Company::create(array_merge(['nipc' => $nipc, 'fiscal_name' => "Empresa {$nipc}", 'plan_id' => $this->planId], $extra));
    }

    private function cars(Company $c, array $statuses): void
    {
        foreach ($statuses as $status) {
            Car::create(['company_id' => $c->id, 'vehicle_type' => 'car', 'status' => $status]);
        }
    }

    public function test_cars_card_counts_only_in_stock_cars_of_active_companies(): void
    {
        $all = ['active', 'available_soon', 'reserved', 'sold', 'draft', 'inactive'];

        $active = $this->company('500030001', ['subscription_status' => 'active']);
        $trial = $this->company('500030002', ['subscription_status' => 'trial', 'trial_ends_at' => now()->addDays(5)]);
        $trialOver = $this->company('500030003', ['subscription_status' => 'trial', 'trial_ends_at' => now()->subDay()]);
        $expired = $this->company('500030004', ['subscription_status' => 'expired']);
        $archived = $this->company('500030005', ['subscription_status' => 'active']);

        $this->cars($active, $all);      // 3 em stock
        $this->cars($trial, $all);       // 3 em stock
        $this->cars($trialOver, $all);   // empresa sem acesso: 0
        $this->cars($expired, $all);     // empresa sem acesso: 0
        $this->cars($archived, $all);
        $archived->delete();             // arquivada (soft delete): 0

        $root = User::factory()->create(['company_id' => $active->id, 'role' => 'root']);

        $res = $this->actingAs($root, 'sanctum')->getJson('/api/v1/admin/platform/summary')->assertOk();
        $this->assertSame(6, $res->json('data.cars_in_stock'));
        $this->assertSame(30, $res->json('data.cars_total'), 'o total geral não filtra estado nem empresa');
    }

    public function test_summary_is_root_only(): void
    {
        $c = $this->company('500030006', ['subscription_status' => 'active']);
        $admin = User::factory()->create(['company_id' => $c->id, 'role' => 'admin']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/platform/summary')->assertStatus(403);
    }
}
