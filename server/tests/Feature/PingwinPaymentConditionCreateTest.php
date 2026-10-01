<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CreatePingwinPaymentConditionJob;
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
 * XPLENDOR — PingWin Condições de Pagamento, ESCRITA/CRIAR (Fatia 2a): endpoint
 * enfileira job + tracking; o JOB chama o serviço (Python mockado), e SÓ após
 * persisted=true (confirmado por releitura) é que o espelho é tocado; caminho de
 * NÃO-persistência (abortado antes do commit) → status erro, espelho intacto;
 * gate do módulo + tenancy. O Python é substituído por um fake.
 */
class PingwinPaymentConditionCreateTest extends TestCase
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

    /** Fake do serviço: captura o $extra enviado ao Python e devolve um result fixo. */
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

    private function persistedResult(): array
    {
        return [
            'ok' => true, 'persisted' => true, 'pingwin_id' => '584955579139782999', 'code' => 'TST1',
            'capture' => ['provisional_id' => '584955579139782796', 'final_id' => '584955579139782999',
                          'discount_sent' => 2.5, 'discount_returned' => 2.5, 'final_id_differs_from_provisional' => true],
            'confirm' => ['id' => '584955579139782999', 'code' => 'TST1', 'description' => 'XPLENDOR Inserir 2a',
                          'discount' => 2.5, 'days' => 15, 'deleted' => 0,
                          'tbdocs_total' => 63, 'tbdocs_linked' => ['1005', '1209']],
            'raw' => ['maindataset' => [['id' => '584955579139782999', 'code' => 'TST1', 'discount' => 2.5, 'days' => 15, 'deleted' => 0]],
                      'tbdocs' => [
                          ['docconfig_id' => '1005', 'description' => 'Fatura', 'entitytype' => 'Cliente', 'deleted' => 0],
                          ['docconfig_id' => '1209', 'description' => 'Fatura de fornecedor', 'entitytype' => 'Fornecedor', 'deleted' => 0],
                          ['docconfig_id' => '1006', 'description' => 'Encomenda de cliente', 'entitytype' => 'Cliente', 'deleted' => 1],
                      ]],
        ];
    }

    public function test_create_endpoint_queues_job_and_tracking(): void
    {
        Bus::fake();
        $resp = $this->actingAs($this->restoUser, 'sanctum')->postJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/payment-conditions",
            ['code' => 'TST1', 'description' => 'XPLENDOR Inserir 2a', 'discount' => 2.5, 'days' => 15, 'tbdocs_unlinked' => ['1006']]
        )->assertStatus(200)->assertJsonPath('data.status', 'a_criar');

        $creationId = $resp->json('data.creation_id');
        $this->assertDatabaseHas('pingwin_paycond_writes', [
            'id' => $creationId, 'company_id' => $this->resto->id, 'action' => 'criar',
            'code' => 'TST1', 'description' => 'XPLENDOR Inserir 2a', 'status' => 'a_criar',
        ]);
        Bus::assertDispatched(CreatePingwinPaymentConditionJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_job_persists_mirror_only_after_confirmation(): void
    {
        $this->app->instance(PingwinService::class, $this->fakeService($this->persistedResult()));

        $write = PingwinPaycondWrite::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id, 'action' => 'criar',
            'code' => 'TST1', 'description' => 'XPLENDOR Inserir 2a', 'discount' => 2.5, 'days' => 15,
            'tbdocs_unlinked' => ['1006'], 'status' => 'a_criar',
        ]);

        CreatePingwinPaymentConditionJob::dispatchSync($this->resto->id, $write->id);

        $write->refresh();
        $this->assertSame('ok', $write->status);
        $this->assertSame('584955579139782999', $write->pingwin_id);

        $cond = PingwinPaymentCondition::where('company_id', $this->resto->id)->where('pingwin_id', '584955579139782999')->first();
        $this->assertNotNull($cond);
        $this->assertSame('TST1', $cond->code);
        $this->assertSame('2.50', (string) $cond->discount);
        $this->assertSame(15, $cond->days);
        $this->assertTrue($cond->is_active);
        // tbdocs guardado (matriz): 1005/1209 vinculados (deleted:0), 1006 desmarcado (deleted:1).
        $this->assertCount(3, $cond->tbdocs);
        $linked = collect($cond->tbdocs)->filter(fn ($d) => (int) ($d['deleted'] ?? 0) === 0)->pluck('docconfig_id')->map(fn ($v) => (string) $v)->values()->all();
        $this->assertEqualsCanonicalizing(['1005', '1209'], $linked);

        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Condição de pagamento criada no PingWin']);
    }

    public function test_job_marks_error_when_not_persisted_and_leaves_mirror_untouched(): void
    {
        // persisted=false simula abortado-antes-do-commit (ex.: passo 2 sem id final).
        $notPersisted = ['ok' => false, 'persisted' => false, 'aborted_before_commit' => true,
                         'error' => 'MERGE não devolveu id final. Abortado antes do commit.'];
        $this->app->instance(PingwinService::class, $this->fakeService($notPersisted));

        $write = PingwinPaycondWrite::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id, 'action' => 'criar',
            'code' => 'TST1', 'description' => 'XPLENDOR Inserir 2a', 'discount' => 2.5, 'days' => 15,
            'tbdocs_unlinked' => [], 'status' => 'a_criar',
        ]);

        CreatePingwinPaymentConditionJob::dispatchSync($this->resto->id, $write->id);

        $write->refresh();
        $this->assertSame('erro', $write->status);
        $this->assertStringContainsString('commit', (string) $write->error_message);
        $this->assertSame(0, PingwinPaymentCondition::where('company_id', $this->resto->id)->count());
    }

    public function test_docs_template_lists_distinct_documents(): void
    {
        PingwinPaymentCondition::create([
            'company_id' => $this->resto->id, 'pingwin_id' => '16010', 'code' => '0', 'description' => 'Fim do mes',
            'discount' => 0, 'days' => 0, 'is_active' => true, 'synced_at' => now(),
            'tbdocs' => [
                ['docconfig_id' => '1005', 'description' => 'Fatura', 'entitytype' => 'Cliente', 'deleted' => 0],
                ['docconfig_id' => '1209', 'description' => 'Fatura de fornecedor', 'entitytype' => 'Fornecedor', 'deleted' => 0],
            ],
        ]);

        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/payment-conditions/docs-template")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.documents')
            ->assertJsonPath('data.documents.1.docconfig_id', '1209');
    }

    public function test_tenancy_blocks_other_company(): void
    {
        $this->actingAs($this->autoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->auto->id}/integrations/pingwin/payment-conditions",
                ['description' => 'x'])
            ->assertStatus(403);
    }
}
