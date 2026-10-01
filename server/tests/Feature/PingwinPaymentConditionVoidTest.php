<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\VoidPingwinPaymentConditionJob;
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
 * XPLENDOR — PingWin Condições de Pagamento, ESCRITA/ANULAR (Fatia 2c, soft-delete):
 * endpoint (só ATIVAS) enfileira job + tracking (action=anular); o JOB chama o serviço
 * (Python mockado) e SÓ após voided_confirmed=true é que o espelho fica is_active=false;
 * não-confirmado → status erro e espelho intacto; rejeita inativa (422) e inexistente
 * (404); tenancy. O Python é mockado.
 */
class PingwinPaymentConditionVoidTest extends TestCase
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
            'synced_at' => now(), 'tbdocs' => [],
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

    public function test_void_endpoint_queues_job_and_tracking(): void
    {
        $this->seedCondition(true);
        Bus::fake();
        $resp = $this->actingAs($this->restoUser, 'sanctum')->deleteJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/payment-conditions/584955579139782859"
        )->assertStatus(200)->assertJsonPath('data.status', 'a_criar');

        $write = PingwinPaycondWrite::find($resp->json('data.creation_id'));
        $this->assertSame('anular', $write->action);
        $this->assertSame('584955579139782859', $write->paycond_id);
        Bus::assertDispatched(VoidPingwinPaymentConditionJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_void_rejects_inactive_and_missing(): void
    {
        $this->seedCondition(false);
        $this->actingAs($this->restoUser, 'sanctum')->deleteJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/payment-conditions/584955579139782859"
        )->assertStatus(422);

        $this->actingAs($this->restoUser, 'sanctum')->deleteJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/payment-conditions/999999999999"
        )->assertStatus(404);
    }

    public function test_job_marks_inactive_only_after_confirmation(): void
    {
        $this->seedCondition(true);
        $this->app->instance(PingwinService::class, $this->fakeService([
            'ok' => true, 'voided_confirmed' => true, 'void_http' => 200,
            'in_state0_ativos' => false, 'in_state1_anulados' => true, 'pingwin_id' => '584955579139782859',
        ]));

        $write = PingwinPaycondWrite::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id, 'action' => 'anular',
            'paycond_id' => '584955579139782859', 'code' => 'TST1', 'description' => 'XPLENDOR Inserir 2a', 'status' => 'a_criar',
        ]);

        VoidPingwinPaymentConditionJob::dispatchSync($this->resto->id, $write->id);

        $write->refresh();
        $this->assertSame('ok', $write->status);
        $cond = PingwinPaymentCondition::where('company_id', $this->resto->id)->where('pingwin_id', '584955579139782859')->first();
        $this->assertFalse($cond->is_active);   // soft-delete refletido no espelho
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Condição de pagamento anulada no PingWin']);
    }

    public function test_job_not_confirmed_keeps_mirror_active(): void
    {
        $this->seedCondition(true);
        $this->app->instance(PingwinService::class, $this->fakeService([
            'ok' => false, 'voided_confirmed' => false, 'void_http' => 200,
            'in_state0_ativos' => true, 'in_state1_anulados' => false,
            'error' => 'ainda nos ativos',
        ]));

        $write = PingwinPaycondWrite::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id, 'action' => 'anular',
            'paycond_id' => '584955579139782859', 'code' => 'TST1', 'description' => 'XPLENDOR Inserir 2a', 'status' => 'a_criar',
        ]);

        VoidPingwinPaymentConditionJob::dispatchSync($this->resto->id, $write->id);

        $write->refresh();
        $this->assertSame('erro', $write->status);
        $cond = PingwinPaymentCondition::where('company_id', $this->resto->id)->where('pingwin_id', '584955579139782859')->first();
        $this->assertTrue($cond->is_active);   // NÃO tocou no espelho (não confirmado)
    }
}
