<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncPingwinUnitsJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinUnit;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — PingWin Unidades (Fase 1, só leitura): guardar (UPSERT idempotente),
 * o CAMINHO COMPLETO do sync (Job→syncUnits→mock Python→BD), a conversão legível
 * ("1 Barril 50lt = 50 Litros"), ativas vs anuladas, gate + tenancy. O Python é
 * substituído por um fake (override invoke) — já devolve só o maindataset.
 */
class PingwinUnitsTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;
    private Company $auto;
    private User $restoUser;
    private User $autoUser;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->resto = Company::create(['nipc' => '500013100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->auto = Company::create(['nipc' => '500013101', 'fiscal_name' => 'Auto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');
        $this->restoUser = User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);
        $this->autoUser = User::factory()->create(['company_id' => $this->auto->id, 'role' => 'admin']);

        CompanyIntegration::create([
            'company_id' => $this->resto->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'segredo', 'config' => ['username' => 'op', 'database' => 'yuko'],
        ]);
    }

    private function fakeService(array $units): PingwinService
    {
        return new class($units) extends PingwinService {
            public array $seen = [];
            public function __construct(private array $u) {}
            protected function invoke(array $payload): array
            {
                $this->seen = $payload;
                return ['ok' => true, 'mode' => 'units', 'units' => $this->u];
            }
        };
    }

    /** Amostra real (do HAR): unidades-base + conversões + um apagado + product-specific. */
    private function sampleUnits(): array
    {
        return [
            ['id' => '11001', 'description' => 'Unidade', 'shortname' => 'UN', 'product_id' => '', 'parent_id' => '', 'unit_value' => 1, 'parent_qnt' => 1, 'purchase' => 1, 'sale' => 1, 'stock' => 1, 'deleted' => 0],
            ['id' => '11003', 'description' => 'Litro', 'shortname' => 'LT', 'product_id' => '', 'parent_id' => '', 'unit_value' => 1, 'purchase' => 1, 'sale' => 1, 'stock' => 1, 'deleted' => 0],
            ['id' => '999', 'description' => 'Barril 50lt', 'shortname' => '50lt', 'product_id' => '', 'parent_id' => '11003', 'unit_value' => 50, 'parent_qnt' => 50, 'purchase' => 1, 'sale' => 0, 'stock' => 1, 'deleted' => 0],
            ['id' => '777', 'description' => 'Unidade', 'shortname' => 'UND', 'product_id' => '', 'parent_id' => '11001', 'unit_value' => 1, 'deleted' => 1], // duplicado/anulado
        ];
    }

    public function test_sync_persists_units_and_requests_units_mode(): void
    {
        $fake = $this->fakeService($this->sampleUnits());
        $count = $fake->syncUnits($this->resto->id);

        $this->assertSame(4, $count);
        $this->assertSame('units', $fake->seen['mode']);
        $this->assertDatabaseCount('pingwin_units', 4);

        $barril = PingwinUnit::where('company_id', $this->resto->id)->where('pingwin_id', '999')->first();
        $this->assertSame('Barril 50lt', $barril->description);
        $this->assertSame('11003', $barril->parent_pingwin_id);
        $this->assertSame(50.0, $barril->unit_value);
        $this->assertTrue($barril->is_active);
        $this->assertTrue($barril->purchase);
        $this->assertFalse($barril->sale);

        $this->assertFalse(PingwinUnit::where('pingwin_id', '777')->first()->is_active); // deleted → anulada
    }

    public function test_sync_is_idempotent_upsert(): void
    {
        $this->fakeService($this->sampleUnits())->syncUnits($this->resto->id);
        $this->fakeService($this->sampleUnits())->syncUnits($this->resto->id);
        $this->assertDatabaseCount('pingwin_units', 4);
    }

    public function test_job_runs_full_chain(): void
    {
        $this->app->instance(PingwinService::class, $this->fakeService($this->sampleUnits()));
        SyncPingwinUnitsJob::dispatchSync($this->resto->id);

        $this->assertDatabaseCount('pingwin_units', 4);
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Unidades atualizadas']);
    }

    public function test_list_shows_readable_conversion_and_active_filter(): void
    {
        $this->fakeService($this->sampleUnits())->syncUnits($this->resto->id);
        $base = "/api/v1/companies/{$this->resto->id}/integrations/pingwin/units";

        // Por defeito o ecrã pede active=1 → 3 ativas (o anulado fica de fora).
        $res = $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?active=1")
            ->assertStatus(200)
            ->assertJsonPath('data.units.total', 3);

        // A conversão é resolvida e legível.
        $barril = collect($res->json('data.units.data'))->firstWhere('description', 'Barril 50lt');
        $this->assertSame('1 Barril 50lt = 50 Litro', $barril['conversion_label']); // usa a descrição real da base (PingWin: "Litro")
        $this->assertSame('Litro', $barril['parent_description']);

        // Uma unidade-base não tem conversão.
        $litro = collect($res->json('data.units.data'))->firstWhere('description', 'Litro');
        $this->assertNull($litro['conversion_label']);

        // active=0 → só a anulada.
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?active=0")
            ->assertStatus(200)
            ->assertJsonPath('data.units.total', 1);
    }

    public function test_search_by_shortname(): void
    {
        $this->fakeService($this->sampleUnits())->syncUnits($this->resto->id);
        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/units?search=50lt&active=1")
            ->assertStatus(200)
            ->assertJsonPath('data.units.total', 1)
            ->assertJsonPath('data.units.data.0.description', 'Barril 50lt');
    }

    public function test_sync_endpoint_queues_job(): void
    {
        Bus::fake();
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/units/sync")
            ->assertStatus(200)->assertJsonPath('data.queued', true);
        Bus::assertDispatched(SyncPingwinUnitsJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_module_gate_and_tenancy(): void
    {
        $this->actingAs($this->autoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->auto->id}/integrations/pingwin/units")
            ->assertStatus(403);
    }
}
