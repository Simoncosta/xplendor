<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\UpdatePingwinPaymentConditionJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinPaycondWrite;
use App\Models\PingwinPaymentCondition;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — PingWin Condições de Pagamento, ESCRITA/EDITAR (Fatia 2b): endpoint
 * (só ATIVAS; code read-only) enfileira job + tracking (action=editar); o JOB chama
 * o serviço (Python mockado) e SÓ após persisted=true é que o espelho reflete o
 * estado CONFIRMADO (incl. a matriz tbdocs com os vínculos preservados); rejeita
 * editar inativa (422) e inexistente (404); tenancy. O Python é mockado.
 */
class PingwinPaymentConditionUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;
    private User $restoUser;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->resto = Company::create(['nipc' => '500013100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');
        $this->restoUser = User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);

        CompanyIntegration::create([
            'company_id' => $this->resto->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'segredo', 'config' => ['username' => 'op', 'database' => 'yuko'],
        ]);
    }

    private function seedCondition(bool $active = true): PingwinPaymentCondition
    {
        return PingwinPaymentCondition::create([
            'company_id' => $this->resto->id, 'pingwin_id' => '584955579139782859', 'code' => 'TST1',
            'description' => 'XPLENDOR Inserir 2a', 'discount' => 2.5, 'days' => 15, 'is_active' => $active,
            'synced_at' => now(),
            'tbdocs' => [
                ['docconfig_id' => '1005', 'description' => 'Fatura', 'entitytype' => 'Cliente', 'deleted' => 0],
                ['docconfig_id' => '1209', 'description' => 'Fatura de fornecedor', 'entitytype' => 'Fornecedor', 'deleted' => 0],
                ['docconfig_id' => '1006', 'description' => 'Encomenda de cliente', 'entitytype' => 'Cliente', 'deleted' => 1],
            ],
        ]);
    }

    private function fakeService(array $result): PingwinService
    {
        return new class($result) extends PingwinService {
            public array $seen = [];
            public function __construct(private array $res) {}
            protected function invoke(array $payload): array
            {
                $this->seen = $payload;
                return ['ok' => true, 'result' => $this->res];
            }
        };
    }

    /** confirm/raw simulando: 1006 vinculado, 1005 desvinculado, 1209 preservado, days 45, discount 3. */
    private function editedResult(): array
    {
        return [
            'ok' => true, 'persisted' => true, 'pingwin_id' => '584955579139782859',
            'confirm' => ['id' => '584955579139782859', 'code' => 'TST1', 'description' => 'XPLENDOR Editado 2b',
                          'discount' => 3, 'days' => 45, 'deleted' => 0, 'tbdocs_total' => 3, 'tbdocs_linked' => ['1006', '1209']],
            'raw' => ['maindataset' => [['id' => '584955579139782859', 'code' => 'TST1', 'discount' => 3, 'days' => 45, 'deleted' => 0]],
                      'tbdocs' => [
                          ['docconfig_id' => '1005', 'description' => 'Fatura', 'entitytype' => 'Cliente', 'deleted' => 1],
                          ['docconfig_id' => '1209', 'description' => 'Fatura de fornecedor', 'entitytype' => 'Fornecedor', 'deleted' => 0],
                          ['docconfig_id' => '1006', 'description' => 'Encomenda de cliente', 'entitytype' => 'Cliente', 'deleted' => 0],
                      ]],
        ];
    }

    public function test_update_endpoint_queues_job_with_only_changes(): void
    {
        $this->seedCondition(true);
        Bus::fake();
        $resp = $this->actingAs($this->restoUser, 'sanctum')->putJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/payment-conditions/584955579139782859",
            ['description' => 'XPLENDOR Editado 2b', 'discount' => 3, 'days' => 45,
             'tbdocs_changes' => [['docconfig_id' => '1006', 'deleted' => 0], ['docconfig_id' => '1005', 'deleted' => 1]]]
        )->assertStatus(200)->assertJsonPath('data.status', 'a_criar');

        $write = PingwinPaycondWrite::find($resp->json('data.creation_id'));
        $this->assertSame('editar', $write->action);
        $this->assertSame('584955579139782859', $write->paycond_id);
        $this->assertSame(45, $write->days);
        $this->assertCount(2, $write->tbdocs_changes);  // só as mudanças
        Bus::assertDispatched(UpdatePingwinPaymentConditionJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_update_rejects_inactive_condition(): void
    {
        $this->seedCondition(false);
        $this->actingAs($this->restoUser, 'sanctum')->putJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/payment-conditions/584955579139782859",
            ['description' => 'x']
        )->assertStatus(422);
    }

    public function test_update_rejects_missing_condition(): void
    {
        $this->actingAs($this->restoUser, 'sanctum')->putJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/payment-conditions/999999999999",
            ['description' => 'x']
        )->assertStatus(404);
    }

    public function test_job_reflects_confirmed_state_with_preserved_links(): void
    {
        $this->seedCondition(true);
        $this->app->instance(PingwinService::class, $this->fakeService($this->editedResult()));

        $write = PingwinPaycondWrite::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id, 'action' => 'editar',
            'paycond_id' => '584955579139782859', 'code' => 'TST1', 'description' => 'XPLENDOR Editado 2b',
            'discount' => 3, 'days' => 45, 'status' => 'a_criar',
            'tbdocs_changes' => [['docconfig_id' => '1006', 'deleted' => 0], ['docconfig_id' => '1005', 'deleted' => 1]],
        ]);

        UpdatePingwinPaymentConditionJob::dispatchSync($this->resto->id, $write->id);

        $write->refresh();
        $this->assertSame('ok', $write->status);

        $cond = PingwinPaymentCondition::where('company_id', $this->resto->id)->where('pingwin_id', '584955579139782859')->first();
        $this->assertSame('XPLENDOR Editado 2b', $cond->description);
        $this->assertSame('3.00', (string) $cond->discount);
        $this->assertSame(45, $cond->days);
        $this->assertSame('TST1', $cond->code);   // code inalterado
        // Vínculos CONFIRMADOS: 1006 agora deleted:0, 1005 deleted:1, 1209 preservado deleted:0.
        $linked = collect($cond->tbdocs)->filter(fn ($d) => (int) ($d['deleted'] ?? 0) === 0)->pluck('docconfig_id')->map(fn ($v) => (string) $v)->values()->all();
        $this->assertEqualsCanonicalizing(['1006', '1209'], $linked);
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Condição de pagamento atualizada no PingWin']);
    }
}
