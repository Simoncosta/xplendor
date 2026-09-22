<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CreatePingwinUnitJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinUnit;
use App\Models\PingwinUnitCreation;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — ⚠️ 1ª ESCRITA no PingWin: criar unidade (Action NEW). Testa a
 * confirmação OBRIGATÓRIA (sem confirm não escreve), o Action:NEW com os campos
 * certos, o caminho completo (Job→createUnit→mock Python→BD reflete), o erro REAL
 * exposto se falhar, auditoria, gate + tenancy.
 */
class PingwinUnitCreateTest extends TestCase
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
        // Unidade-base (parent) já sincronizada.
        PingwinUnit::create(['company_id' => $this->resto->id, 'pingwin_id' => '11001', 'description' => 'Unidade', 'shortname' => 'UN', 'is_active' => true]);
    }

    /** PingwinService com o Python (invoke) substituído; regista o que foi enviado. */
    private function fakeService(array $return): PingwinService
    {
        return new class($return) extends PingwinService {
            public array $seen = [];
            public function __construct(private array $ret) {}
            protected function invoke(array $payload): array
            {
                $this->seen = $payload;
                return $this->ret;
            }
        };
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'confirm' => true, 'description' => 'UNIDADE TESTE X', 'shortname' => '*UNX*',
            'parent_id' => '11001', 'parent_qnt' => 1,
        ], $over);
    }

    public function test_confirmation_is_mandatory_no_write_without_it(): void
    {
        Bus::fake();
        // Sem confirm → 422 e NENHUM job (nada vai ao PingWin).
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/units/create", $this->payload(['confirm' => false]))
            ->assertStatus(422);
        Bus::assertNotDispatched(CreatePingwinUnitJob::class);
        $this->assertDatabaseCount('pingwin_unit_creations', 0);
    }

    public function test_create_queues_job_and_records_audit(): void
    {
        Bus::fake();
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/units/create", $this->payload())
            ->assertStatus(200)->assertJsonPath('data.status', 'a_criar');

        Bus::assertDispatched(CreatePingwinUnitJob::class, fn ($j) => $j->companyId === $this->resto->id);
        $this->assertDatabaseHas('pingwin_unit_creations', [
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id,
            'description' => 'UNIDADE TESTE X', 'status' => 'a_criar',
        ]);
    }

    public function test_parent_must_exist_and_belong_to_company(): void
    {
        Bus::fake();
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/units/create", $this->payload(['parent_id' => '99999']))
            ->assertStatus(422);
        Bus::assertNotDispatched(CreatePingwinUnitJob::class);
    }

    public function test_full_chain_creates_unit_and_reflects_locally(): void
    {
        // O Python devolve a unidade criada (com novo id).
        $this->app->instance(PingwinService::class, $this->fakeService([
            'ok' => true, 'mode' => 'create_unit', 'unit' => [
                'id' => '584955579139771856', 'description' => 'UNIDADE TESTE X', 'shortname' => '*UNX*',
                'parent_id' => '11001', 'parent_qnt' => 1, 'purchase' => 1, 'sale' => 1, 'stock' => 1, 'deleted' => 0,
            ],
        ]));
        $creation = PingwinUnitCreation::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id,
            'description' => 'UNIDADE TESTE X', 'shortname' => '*UNX*', 'parent_pingwin_id' => '11001', 'parent_qnt' => 1, 'status' => 'a_criar',
        ]);

        CreatePingwinUnitJob::dispatchSync($this->resto->id, $creation->id);

        $creation->refresh();
        $this->assertSame('criada', $creation->status);
        $this->assertSame('584955579139771856', $creation->pingwin_id);
        // A unidade nova reflete na BD local (a tela vai mostrá-la).
        $this->assertDatabaseHas('pingwin_units', [
            'company_id' => $this->resto->id, 'pingwin_id' => '584955579139771856',
            'description' => 'UNIDADE TESTE X', 'parent_pingwin_id' => '11001', 'is_active' => true,
        ]);
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Unidade criada no PingWin']);
    }

    public function test_new_fields_flow_to_pingwin_payload(): void
    {
        // Captura o que é enviado ao Python.
        $fake = new class extends \App\Services\PingwinService {
            public array $seen = [];
            protected function invoke(array $payload): array
            {
                $this->seen = $payload;
                return ['ok' => true, 'unit' => array_merge(['id' => '900'], $payload['unit'])];
            }
        };
        $this->app->instance(\App\Services\PingwinService::class, $fake);

        $creation = PingwinUnitCreation::create([
            'company_id' => $this->resto->id, 'description' => 'Caixa 6', 'shortname' => 'CX6',
            'parent_pingwin_id' => '11001', 'parent_qnt' => 6, 'warn_maxsale_qnt' => 5,
            'frac_unit' => false, 'external_measure' => '0', 'status' => 'a_criar',
        ]);
        CreatePingwinUnitJob::dispatchSync($this->resto->id, $creation->id);

        $sent = $fake->seen['unit'];
        $this->assertSame(0, $sent['frac_unit']);          // checkbox 0 preservado (não descartado)
        $this->assertEquals(5, $sent['warn_maxsale_qnt']); // qnt. máx. venda
        $this->assertSame(0, $sent['external_measure']);   // medição externa (checkbox) 0
    }

    public function test_full_chain_exposes_real_pingwin_error(): void
    {
        // O Python devolve ok:false com o motivo REAL.
        $this->app->instance(PingwinService::class, $this->fakeService([
            'ok' => false, 'error' => 'shortname duplicado no PingWin',
        ]));
        $creation = PingwinUnitCreation::create([
            'company_id' => $this->resto->id, 'description' => 'X', 'shortname' => 'UN', 'parent_pingwin_id' => '11001', 'status' => 'a_criar',
        ]);

        CreatePingwinUnitJob::dispatchSync($this->resto->id, $creation->id);

        $creation->refresh();
        $this->assertSame('erro', $creation->status);
        $this->assertStringContainsString('shortname duplicado no PingWin', $creation->error_message); // erro REAL, não genérico
        // NÃO criou unidade local em caso de erro.
        $this->assertDatabaseMissing('pingwin_units', ['company_id' => $this->resto->id, 'description' => 'X']);
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Falha ao criar a unidade']);
    }

    public function test_poll_endpoint_returns_status(): void
    {
        $creation = PingwinUnitCreation::create([
            'company_id' => $this->resto->id, 'description' => 'X', 'shortname' => 'UN', 'status' => 'criada', 'pingwin_id' => '777',
        ]);
        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/units/creations/{$creation->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'criada')
            ->assertJsonPath('data.pingwin_id', '777');
    }

    public function test_module_gate_and_tenancy(): void
    {
        $this->actingAs($this->autoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->auto->id}/integrations/pingwin/units/create", $this->payload())
            ->assertStatus(403);
    }
}
