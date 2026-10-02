<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CreatePingwinDocumentConfigJob;
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
 * XPLENDOR — PingWin Documentos CRIAR (Fase D3): endpoint (descrição obrigatória) enfileira
 * job + tracking (action=criar, docconfig_id vazio); o JOB chama o serviço (Python mockado)
 * e SÓ após persisted=true INSERE o novo documento no espelho (a partir do reread, incl.
 * filhas no template) e grava o id final no tracking; gate de módulo. O Python é mockado.
 */
class PingwinDocumentConfigCreateTest extends TestCase
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

    /** Simula criação confirmada; code TRUNCADO pelo servidor (enviado TSTD1X, devolvido TSTD1). */
    private function createdResult(): array
    {
        return [
            'ok' => true, 'persisted' => true, 'pingwin_id' => '584955579139783999',
            'capture' => ['provisional_id' => '584955579139783999', 'code_sent' => 'TSTD1X', 'code_returned' => 'TSTD1', 'final_id' => '584955579139783999'],
            'confirm' => ['id' => '584955579139783999', 'code' => 'TSTD1', 'description' => 'XPLENDOR D3 Teste', 'deleted' => 0, 'code_truncated' => true],
            'raw' => [
                'maindataset' => [['code' => 'TSTD1', 'description' => 'XPLENDOR D3 Teste', 'doctype_id' => '17001', 'docfiscaltype_id' => '9500', 'stock_signal' => '1', 'deleted' => 0]],
                'docconfig_docaccount' => [['docaccount_id' => '13000', 'deleted' => 1, 'credit' => 0, 'debit' => 0]],
                'docconfig_paycond' => [['paycond_id' => '16010', 'deleted' => 1]],
                'contacttype' => [['id' => '6001', 'description' => 'X']],
                'additionalfields.maindataset' => [['ignore_pending_qnt' => 0]],
                '_storedataset' => [['store' => '1']],
            ],
        ];
    }

    public function test_create_endpoint_requires_description_and_queues(): void
    {
        Bus::fake();
        // sem descrição → 422
        $this->actingAs($this->restoUser, 'sanctum')->postJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents",
            ['fields' => ['code' => 'TSTD1']]
        )->assertStatus(422);

        // com descrição → queued + tracking action=criar, docconfig_id vazio
        $resp = $this->actingAs($this->restoUser, 'sanctum')->postJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents",
            ['fields' => ['code' => 'TSTD1', 'description' => 'XPLENDOR D3 Teste', 'doctype_id' => '17001']]
        )->assertStatus(200)->assertJsonPath('data.status', 'a_criar');

        $write = PingwinDocconfigWrite::find($resp->json('data.write_id'));
        $this->assertSame('criar', $write->action);
        $this->assertSame('', $write->docconfig_id);
        $this->assertSame('17001', $write->fields['doctype_id']);
        Bus::assertDispatched(CreatePingwinDocumentConfigJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_job_inserts_new_mirror_and_tolerates_truncated_code(): void
    {
        $this->app->instance(PingwinService::class, $this->fakeService($this->createdResult()));

        $write = PingwinDocconfigWrite::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id, 'action' => 'criar',
            'docconfig_id' => '', 'code' => 'TSTD1X', 'description' => 'XPLENDOR D3 Teste',
            'fields' => ['code' => 'TSTD1X', 'description' => 'XPLENDOR D3 Teste'], 'status' => 'a_criar',
        ]);
        CreatePingwinDocumentConfigJob::dispatchSync($this->resto->id, $write->id);

        $write->refresh();
        $this->assertSame('ok', $write->status);
        $this->assertSame('584955579139783999', $write->docconfig_id);   // id final gravado

        $d = PingwinDocumentConfig::where('company_id', $this->resto->id)->where('external_id', '584955579139783999')->first();
        $this->assertNotNull($d);                       // novo documento inserido no espelho
        $this->assertSame('TSTD1', $d->code);           // code CONFIRMADO (truncado), não o enviado
        $this->assertSame('XPLENDOR D3 Teste', $d->description);
        $this->assertSame('17001', $d->doctype_id);
        $d->makeVisible(PingwinDocumentConfig::RICH_JSON_COLUMNS);
        $this->assertCount(1, $d->docconfig_docaccount);  // filhas template guardadas
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Documento criado no PingWin']);
    }

    public function test_module_gate_blocks_other_company(): void
    {
        $this->actingAs($this->autoUser, 'sanctum')->postJson(
            "/api/v1/companies/{$this->auto->id}/integrations/pingwin/documents",
            ['fields' => ['description' => 'x']]
        )->assertStatus(403);
    }
}
