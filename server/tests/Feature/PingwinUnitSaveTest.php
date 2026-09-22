<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SavePingwinUnitJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinCatalogItem;
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
 * XPLENDOR — ⚠️ ESCRITA editar/anular unidade (Action EDIT,SAVE; deleted decide).
 * Testa: confirmação obrigatória; editar grava o objeto todo com deleted=0; anular
 * com deleted=1; o objeto COMPLETO (raw) é reenviado; reflete na tela; erro real
 * exposto; aviso de uso; logout garantido (no Python); gate + tenancy.
 */
class PingwinUnitSaveTest extends TestCase
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
        // Unidade-base + a unidade de teste (com raw completo).
        PingwinUnit::create(['company_id' => $this->resto->id, 'pingwin_id' => '11003', 'description' => 'Litro', 'shortname' => 'LT', 'is_active' => true]);
    }

    private function testUnit(): PingwinUnit
    {
        return PingwinUnit::create([
            'company_id' => $this->resto->id, 'pingwin_id' => '999', 'description' => 'UNIDADE TESTE X', 'shortname' => '*UNX*',
            'parent_pingwin_id' => '11003', 'unit_value' => 2, 'is_active' => true,
            'raw' => ['id' => '999', 'key' => '00000030', 'description' => 'UNIDADE TESTE X', 'shortname' => '*UNX*',
                'parent_id' => '11003', 'parent_qnt' => 2, 'unit_value' => 2, 'tare' => 1, 'frac_unit' => 1,
                'print_label' => 0, 'warn_maxsale_qnt' => 1, 'net_weight' => 0, 'external_measure' => 0, 'deleted' => 0],
        ]);
    }

    /** Fake do serviço: captura o objeto enviado ao Python + devolve-o. */
    private function fakeService(?array $return = null): PingwinService
    {
        return new class($return) extends PingwinService {
            public array $seen = [];
            public function __construct(private ?array $ret) {}
            protected function invoke(array $payload): array
            {
                $this->seen = $payload;
                if ($this->ret !== null) {
                    return $this->ret;
                }
                return ['ok' => true, 'mode' => 'save_unit', 'unit' => $payload['unit']]; // eco do que enviámos
            }
        };
    }

    public function test_edit_confirmation_is_mandatory(): void
    {
        Bus::fake();
        $unit = $this->testUnit();
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/units/{$unit->id}/edit",
                ['confirm' => false, 'description' => 'X', 'shortname' => 'X', 'parent_id' => '11003'])
            ->assertStatus(422);
        Bus::assertNotDispatched(SavePingwinUnitJob::class);
    }

    public function test_edit_queues_job_with_edit_action(): void
    {
        Bus::fake();
        $unit = $this->testUnit();
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/units/{$unit->id}/edit",
                ['confirm' => true, 'description' => 'UNIDADE TESTE Y', 'shortname' => '*UNY*', 'parent_id' => '11003', 'parent_qnt' => 3])
            ->assertStatus(200)->assertJsonPath('data.status', 'a_criar');
        Bus::assertDispatched(SavePingwinUnitJob::class);
        $this->assertDatabaseHas('pingwin_unit_creations', [
            'company_id' => $this->resto->id, 'action' => 'edit', 'unit_id' => $unit->id, 'description' => 'UNIDADE TESTE Y',
        ]);
    }

    public function test_edit_full_chain_sends_complete_object_deleted_0_and_reflects(): void
    {
        $unit = $this->testUnit();
        $fake = $this->fakeService();
        $this->app->instance(PingwinService::class, $fake);

        $write = PingwinUnitCreation::create([
            'company_id' => $this->resto->id, 'action' => 'edit', 'unit_id' => $unit->id,
            'description' => 'UNIDADE TESTE Y', 'shortname' => '*UNY*', 'parent_pingwin_id' => '11003', 'parent_qnt' => 3, 'status' => 'a_criar',
        ]);
        SavePingwinUnitJob::dispatchSync($this->resto->id, $write->id);

        // Enviou o objeto COMPLETO (do raw) com os campos novos + deleted=0.
        $sent = $fake->seen['unit'];
        $this->assertSame('999', $sent['id']);
        $this->assertSame('UNIDADE TESTE Y', $sent['description']);   // campo alterado
        $this->assertEquals(3, $sent['parent_qnt']);
        $this->assertEquals(3, $sent['unit_value']);                  // fator mantém-se coerente
        $this->assertArrayHasKey('tare', $sent);                      // campo do raw preservado (objeto todo)
        $this->assertSame(0, $sent['deleted']);                       // editar = deleted 0

        $write->refresh();
        $this->assertSame('criada', $write->status);
        $unit->refresh();
        $this->assertSame('UNIDADE TESTE Y', $unit->description);      // reflete na BD local
        $this->assertTrue($unit->is_active);
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Unidade alterada no PingWin']);
    }

    public function test_anular_full_chain_sends_deleted_1_and_marks_inactive(): void
    {
        $unit = $this->testUnit();
        $fake = $this->fakeService();
        $this->app->instance(PingwinService::class, $fake);

        $write = PingwinUnitCreation::create([
            'company_id' => $this->resto->id, 'action' => 'anular', 'unit_id' => $unit->id,
            'description' => 'UNIDADE TESTE X', 'shortname' => '*UNX*', 'status' => 'a_criar',
        ]);
        SavePingwinUnitJob::dispatchSync($this->resto->id, $write->id);

        $this->assertSame(1, $fake->seen['unit']['deleted']);         // anular = deleted 1
        $this->assertSame('UNIDADE TESTE X', $fake->seen['unit']['description']); // mantém o resto (objeto todo)
        $unit->refresh();
        $this->assertFalse($unit->is_active);                         // passou a anulada (reflete)
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Unidade anulada no PingWin']);
    }

    public function test_save_exposes_real_pingwin_error(): void
    {
        $unit = $this->testUnit();
        $this->app->instance(PingwinService::class, $this->fakeService(['ok' => false, 'error' => 'unidade em uso, não pode ser anulada']));
        $write = PingwinUnitCreation::create([
            'company_id' => $this->resto->id, 'action' => 'anular', 'unit_id' => $unit->id,
            'description' => 'UNIDADE TESTE X', 'shortname' => '*UNX*', 'status' => 'a_criar',
        ]);
        SavePingwinUnitJob::dispatchSync($this->resto->id, $write->id);

        $write->refresh();
        $this->assertSame('erro', $write->status);
        $this->assertStringContainsString('unidade em uso', $write->error_message); // erro REAL, não genérico
        $unit->refresh();
        $this->assertTrue($unit->is_active); // NÃO mudou o estado local em caso de erro
    }

    public function test_usage_endpoint_counts_articles_using_the_unit(): void
    {
        $unit = $this->testUnit();
        PingwinCatalogItem::create(['company_id' => $this->resto->id, 'pingwin_id' => 'A1', 'description' => 'Cerveja', 'saleunit' => '*UNX*']);
        PingwinCatalogItem::create(['company_id' => $this->resto->id, 'pingwin_id' => 'A2', 'description' => 'Outro', 'purchaseunit' => 'UNIDADE TESTE X']);
        PingwinCatalogItem::create(['company_id' => $this->resto->id, 'pingwin_id' => 'A3', 'description' => 'Nada', 'saleunit' => 'KG']);

        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/units/{$unit->id}/usage")
            ->assertStatus(200)
            ->assertJsonPath('data.usage_count', 2); // A1 (shortname) + A2 (description)
    }

    public function test_gate_and_tenancy(): void
    {
        $unit = $this->testUnit();
        $this->actingAs($this->autoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->auto->id}/integrations/pingwin/units/{$unit->id}/anular", ['confirm' => true])
            ->assertStatus(403);
    }
}
