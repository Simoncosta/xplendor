<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\VoidPingwinDocumentConfigJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinDocconfigWrite;
use App\Models\PingwinDocumentConfig;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — PingWin Documentos ANULAR (Fase D4, soft-delete): endpoint (só ATIVOS) enfileira
 * job + tracking (action=anular); o JOB chama o serviço (Python mockado) e SÓ após
 * voided_confirmed=true é que o espelho fica deleted=true; não-confirmado → espelho intacto;
 * rejeita inativo (422) e inexistente (404); gate de módulo. O Python é mockado.
 */
class PingwinDocumentConfigVoidTest extends TestCase
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

    private function seedDoc(bool $active = true): PingwinDocumentConfig
    {
        return PingwinDocumentConfig::create([
            'company_id' => $this->resto->id, 'external_id' => '584955579139783634', 'code' => 'TSTD2',
            'description' => 'XPLENDOR D3 Job', 'entitytype' => 'Cliente', 'deleted' => ! $active, 'synced_at' => now(),
        ]);
    }

    private function fakeService(array $result): PingwinService
    {
        return new class($result) extends PingwinService {
            public function __construct(private array $res) {}
            protected function invoke(array $payload): array
            {
                return ['ok' => true, 'result' => $this->res];
            }
        };
    }

    public function test_void_endpoint_queues_and_tracking(): void
    {
        $this->seedDoc(true);
        Bus::fake();
        $resp = $this->actingAs($this->restoUser, 'sanctum')->deleteJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/584955579139783634"
        )->assertStatus(200)->assertJsonPath('data.status', 'a_criar');

        $write = PingwinDocconfigWrite::find($resp->json('data.write_id'));
        $this->assertSame('anular', $write->action);
        $this->assertSame('584955579139783634', $write->docconfig_id);
        Bus::assertDispatched(VoidPingwinDocumentConfigJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_void_rejects_inactive_and_missing(): void
    {
        $this->seedDoc(false);
        $this->actingAs($this->restoUser, 'sanctum')->deleteJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/584955579139783634"
        )->assertStatus(422);

        $this->actingAs($this->restoUser, 'sanctum')->deleteJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/999999999999"
        )->assertStatus(404);
    }

    public function test_job_marks_deleted_only_after_confirmation(): void
    {
        $this->seedDoc(true);
        $this->app->instance(PingwinService::class, $this->fakeService([
            'ok' => true, 'voided_confirmed' => true, 'void_http' => 200,
            'in_state0_ativos' => false, 'in_state1_anulados' => true, 'pingwin_id' => '584955579139783634',
        ]));

        $write = PingwinDocconfigWrite::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id, 'action' => 'anular',
            'docconfig_id' => '584955579139783634', 'code' => 'TSTD2', 'description' => 'XPLENDOR D3 Job', 'status' => 'a_criar',
        ]);
        VoidPingwinDocumentConfigJob::dispatchSync($this->resto->id, $write->id);

        $this->assertSame('ok', $write->fresh()->status);
        $this->assertTrue((bool) PingwinDocumentConfig::where('external_id', '584955579139783634')->first()->deleted);
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Documento anulado no PingWin']);
    }

    public function test_job_not_confirmed_keeps_mirror_active(): void
    {
        $this->seedDoc(true);
        $this->app->instance(PingwinService::class, $this->fakeService([
            'ok' => false, 'voided_confirmed' => false, 'in_state0_ativos' => true, 'in_state1_anulados' => false, 'error' => 'ainda ativo',
        ]));

        $write = PingwinDocconfigWrite::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id, 'action' => 'anular',
            'docconfig_id' => '584955579139783634', 'code' => 'TSTD2', 'description' => 'XPLENDOR D3 Job', 'status' => 'a_criar',
        ]);
        VoidPingwinDocumentConfigJob::dispatchSync($this->resto->id, $write->id);

        $this->assertSame('erro', $write->fresh()->status);
        $this->assertFalse((bool) PingwinDocumentConfig::where('external_id', '584955579139783634')->first()->deleted);
    }

    public function test_module_gate_blocks_other_company(): void
    {
        $this->actingAs($this->autoUser, 'sanctum')->deleteJson(
            "/api/v1/companies/{$this->auto->id}/integrations/pingwin/documents/584955579139783634"
        )->assertStatus(403);
    }
}
