<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinDailySale;
use App\Models\PingwinLocation;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Cadastro manual de lojas PingWin + o sync a usar os winrest_store_id
 * cadastrados (desbloqueia o "Stores" do relatório). Cobre: CRUD com tenancy;
 * o "Stores" composto das lojas ativas; as vendas ligadas à loja certa por nome;
 * empresa sem lojas → mensagem clara; gate do módulo.
 */
class PingwinLocationsTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;
    private Company $auto;
    private Company $other;
    private \App\Models\User $restoUser;
    private \App\Models\User $autoUser;
    private \App\Models\User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->resto = Company::create(['nipc' => '500006100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->auto = Company::create(['nipc' => '500006101', 'fiscal_name' => 'Auto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500006102', 'fiscal_name' => 'Resto B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');
        app(CompanyModuleService::class)->applyPreset($this->other->id, 'restaurant');

        $this->restoUser = \App\Models\User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);
        $this->autoUser = \App\Models\User::factory()->create(['company_id' => $this->auto->id, 'role' => 'admin']);
        $this->otherUser = \App\Models\User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
    }

    private function url(int $companyId, string $suffix = ''): string
    {
        return "/api/v1/companies/{$companyId}/integrations/pingwin/locations{$suffix}";
    }

    public function test_can_register_list_and_delete_a_location(): void
    {
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->resto->id), [
                'winrest_store_id' => '584955579139649880',
                'winrest_name' => 'Yuko Lisboa',
                'display_name' => 'Lisboa',
                'opened_on' => '2026-01-15',
            ])->assertStatus(200);

        $this->assertDatabaseHas('pingwin_locations', [
            'company_id' => $this->resto->id, 'winrest_store_id' => '584955579139649880', 'display_name' => 'Lisboa',
        ]);

        $loc = PingwinLocation::where('company_id', $this->resto->id)->first();
        $this->actingAs($this->restoUser, 'sanctum')->getJson($this->url($this->resto->id))
            ->assertStatus(200)->assertJsonPath('data.0.winrest_store_id', '584955579139649880');

        $this->actingAs($this->restoUser, 'sanctum')->deleteJson($this->url($this->resto->id, "/{$loc->id}"))->assertStatus(200);
        $this->assertDatabasemissing('pingwin_locations', ['id' => $loc->id]);
    }

    public function test_is_active_accepts_1_and_0_without_error(): void
    {
        // O frontend envia is_active como 1/0 (multipart-safe). Criar inativa (0).
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->resto->id), ['winrest_store_id' => '900', 'display_name' => 'X', 'is_active' => 0])
            ->assertStatus(200);
        $loc = PingwinLocation::where('company_id', $this->resto->id)->where('winrest_store_id', '900')->first();
        $this->assertFalse((bool) $loc->is_active);

        // Editar para ativa (1) — sem erro.
        $this->actingAs($this->restoUser, 'sanctum')
            ->patchJson($this->url($this->resto->id, "/{$loc->id}"), ['winrest_store_id' => '900', 'is_active' => 1])
            ->assertStatus(200);
        $this->assertTrue((bool) $loc->fresh()->is_active);
    }

    public function test_duplicate_store_id_is_rejected(): void
    {
        PingwinLocation::create(['company_id' => $this->resto->id, 'winrest_store_id' => '111', 'display_name' => 'A']);

        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->resto->id), ['winrest_store_id' => '111'])
            ->assertStatus(422);
    }

    public function test_module_gate_and_tenancy(): void
    {
        // Empresa automotiva não tem o módulo pingwin → 403.
        $this->actingAs($this->autoUser, 'sanctum')->getJson($this->url($this->auto->id))->assertStatus(403);

        // Utilizador de outra empresa não mexe nas lojas da resto.
        $loc = PingwinLocation::create(['company_id' => $this->resto->id, 'winrest_store_id' => '222', 'display_name' => 'X']);
        $this->actingAs($this->otherUser, 'sanctum')
            ->patchJson($this->url($this->other->id, "/{$loc->id}"), ['winrest_store_id' => '222'])
            ->assertStatus(404); // não pertence à empresa da rota
    }

    // ── O sync usa os winrest_store_id cadastrados no "Stores" ─────────────────

    /** Fake que captura o payload enviado ao Python. */
    private function capturingService(array $sales = []): PingwinService
    {
        return new class($sales) extends PingwinService {
            public array $seen = [];
            public function __construct(private array $salesRows) {}
            protected function invoke(array $payload): array
            {
                $this->seen = $payload;
                if (($payload['mode'] ?? null) === 'validate') {
                    return ['ok' => true];
                }
                return ['ok' => true, 'date' => $payload['date'] ?? '2026-09-17', 'stores' => [], 'sales' => $this->salesRows];
            }
        };
    }

    private function connect(): void
    {
        // Grava credenciais (estado validating) sem tocar docker.
        $this->capturingService()->saveCredentials($this->resto->id, ['username' => 'u', 'database' => 'yuko'], 'segredo');
        CompanyIntegration::where('company_id', $this->resto->id)->platform('pingwin')->update(['status' => 'active']);
    }

    public function test_sync_composes_stores_from_registered_locations(): void
    {
        $this->connect();
        PingwinLocation::create(['company_id' => $this->resto->id, 'winrest_store_id' => '584955579139649880', 'winrest_name' => 'Yuko Lisboa', 'display_name' => 'Lisboa', 'is_active' => true]);
        PingwinLocation::create(['company_id' => $this->resto->id, 'winrest_store_id' => '1099845342604', 'winrest_name' => 'Yuko Porto', 'display_name' => 'Porto', 'is_active' => true]);

        $sales = [
            ['loja' => 'Yuko Lisboa', 'vendas_liquidas' => 1000.00, 'valor_faturado' => 1200.00],
            ['loja' => 'Yuko Porto', 'vendas_liquidas' => 500.00, 'valor_faturado' => 600.00],
        ];
        $svc = $this->capturingService($sales);

        $svc->sync($this->resto->id, '2026-09-17');

        // O "Stores" leva os winrest_store_id cadastrados (CSV) — desbloqueia o relatório.
        $this->assertSame('584955579139649880,1099845342604', $svc->seen['stores']);

        // As vendas caem na loja certa (match por nome).
        $lisboa = PingwinLocation::where('company_id', $this->resto->id)->where('winrest_store_id', '584955579139649880')->first();
        $sale = PingwinDailySale::where('location_id', $lisboa->id)->whereDate('business_date', '2026-09-17')->first();
        $this->assertNotNull($sale);
        $this->assertSame(120000, $sale->invoiced_cents);
        $this->assertSame(100000, $sale->net_cents);
        $this->assertSame(2, PingwinDailySale::where('company_id', $this->resto->id)->count());
    }

    public function test_sync_without_locations_gives_clear_message(): void
    {
        $this->connect(); // ligado, mas sem lojas cadastradas.

        $svc = $this->capturingService();
        try {
            $svc->sync($this->resto->id, '2026-09-17');
            $this->fail('Deveria ter lançado ValidationException por falta de lojas.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('Cadastra pelo menos uma loja', $e->getMessage());
        }
        // Não chegou a invocar o Python.
        $this->assertSame([], $svc->seen);
    }

    public function test_queue_trigger_blocks_without_locations(): void
    {
        $this->connect();
        \Illuminate\Support\Facades\Bus::fake();

        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/sync-queue", ['date' => '2026-09-17'])
            ->assertStatus(422);

        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SyncPingwinJob::class);
    }
}
