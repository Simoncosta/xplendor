<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\UpdatePingwinDocumentConfigJob;
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
 * XPLENDOR — PingWin Documentos EDITAR maindataset (Fase D1): endpoint (só ATIVOS,
 * whitelist de campos) enfileira job + tracking (action=editar); o JOB chama o serviço
 * (Python mockado) e SÓ após persisted=true é que o espelho reflete o maindataset
 * confirmado (as filhas NÃO mudam); rejeita inativo (422), inexistente (404), sem campos
 * (422); polling; gate de módulo. O Python é mockado.
 */
class PingwinDocumentConfigUpdateTest extends TestCase
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
            'company_id' => $this->resto->id, 'external_id' => '1226', 'code' => 'FTAF',
            'description' => 'Auto Fatura UP', 'entitytype' => 'Fornecedor', 'deleted' => ! $active,
            'taxscenario_id' => '3101', 'doctype_id' => '17001', 'docseries_id' => '4002',
            'synced_at' => now(), 'rich_synced_at' => now(),
            'raw' => ['code' => 'FTAF', 'description' => 'Auto Fatura UP', 'number_copies' => 1],
            'docconfig_paycond' => [['paycond_id' => '16010', 'deleted' => 0]],
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

    private function persistedResult(): array
    {
        return [
            'ok' => true, 'persisted' => true, 'pingwin_id' => '1226',
            'confirm' => ['maindataset' => ['description' => 'Auto Fatura UP EDIT', 'number_copies' => 3], 'deleted' => 0,
                          'children_counts' => ['docconfig_paycond' => 3, 'docconfig_docaccount' => 12]],
            'raw' => ['maindataset' => [['code' => 'FTAF', 'description' => 'Auto Fatura UP EDIT',
                      'number_copies' => 3, 'taxscenario_id' => '3101', 'doctype_id' => '17001', 'docseries_id' => '4002', 'deleted' => 0]]],
        ];
    }

    public function test_update_endpoint_whitelists_and_queues(): void
    {
        $this->seedDoc(true);
        Bus::fake();
        $resp = $this->actingAs($this->restoUser, 'sanctum')->putJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/1226",
            ['fields' => ['description' => 'Auto Fatura UP EDIT', 'number_copies' => 3, 'code' => 'HACK', 'lixo' => 'x', 'settled' => 0]]
        )->assertStatus(200)->assertJsonPath('data.status', 'a_criar');

        $write = PingwinDocconfigWrite::find($resp->json('data.write_id'));
        $this->assertSame('editar', $write->action);
        $this->assertSame('1226', $write->docconfig_id);
        // whitelist: description/number_copies entram; code/lixo são removidos.
        $this->assertArrayHasKey('description', $write->fields);
        $this->assertArrayHasKey('number_copies', $write->fields);
        $this->assertArrayNotHasKey('code', $write->fields);
        $this->assertArrayNotHasKey('lixo', $write->fields);
        // S2: "Pago" (settled) é só de leitura — a Conta Corrente depende dele; nunca vai na escrita.
        $this->assertArrayNotHasKey('settled', $write->fields);
        Bus::assertDispatched(UpdatePingwinDocumentConfigJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_update_rejects_inactive_missing_and_empty(): void
    {
        $this->seedDoc(false);
        $this->actingAs($this->restoUser, 'sanctum')->putJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/1226", ['fields' => ['description' => 'x']]
        )->assertStatus(422);  // inativo

        $this->actingAs($this->restoUser, 'sanctum')->putJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/999999", ['fields' => ['description' => 'x']]
        )->assertStatus(404);  // inexistente

        PingwinDocumentConfig::where('external_id', '1226')->update(['deleted' => false]);
        $this->actingAs($this->restoUser, 'sanctum')->putJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/1226", ['fields' => ['lixo' => 'x']]
        )->assertStatus(422);  // sem campos editáveis
    }

    public function test_job_updates_mirror_maindataset_after_confirmation(): void
    {
        $this->seedDoc(true);
        $this->app->instance(PingwinService::class, $this->fakeService($this->persistedResult()));

        $write = PingwinDocconfigWrite::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id, 'action' => 'editar',
            'docconfig_id' => '1226', 'code' => 'FTAF', 'description' => 'Auto Fatura UP EDIT',
            'fields' => ['description' => 'Auto Fatura UP EDIT', 'number_copies' => 3], 'status' => 'a_criar',
        ]);

        UpdatePingwinDocumentConfigJob::dispatchSync($this->resto->id, $write->id);

        $write->refresh();
        $this->assertSame('ok', $write->status);
        $d = PingwinDocumentConfig::where('company_id', $this->resto->id)->where('external_id', '1226')->first();
        $d->makeVisible(PingwinDocumentConfig::RICH_JSON_COLUMNS);
        $this->assertSame('Auto Fatura UP EDIT', $d->description);     // maindataset refletido
        $this->assertSame(3, $d->raw['number_copies']);
        $this->assertSame('3101', $d->taxscenario_id);
        // as filhas NÃO foram tocadas na D1 (continuam como estavam no espelho)
        $this->assertCount(1, $d->docconfig_paycond);
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Documento atualizado no PingWin']);
    }

    public function test_job_not_persisted_keeps_mirror(): void
    {
        $this->seedDoc(true);
        $this->app->instance(PingwinService::class, $this->fakeService(['ok' => false, 'persisted' => false, 'error' => 'não confirmado']));

        $write = PingwinDocconfigWrite::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id, 'action' => 'editar',
            'docconfig_id' => '1226', 'code' => 'FTAF', 'description' => 'x',
            'fields' => ['description' => 'x'], 'status' => 'a_criar',
        ]);
        UpdatePingwinDocumentConfigJob::dispatchSync($this->resto->id, $write->id);

        $write->refresh();
        $this->assertSame('erro', $write->status);
        $this->assertSame('Auto Fatura UP', PingwinDocumentConfig::where('external_id', '1226')->first()->description);
    }

    public function test_write_polling_endpoint(): void
    {
        $this->seedDoc(true);
        $write = PingwinDocconfigWrite::create([
            'company_id' => $this->resto->id, 'action' => 'editar', 'docconfig_id' => '1226',
            'description' => 'x', 'fields' => [], 'status' => 'ok',
        ]);
        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/writes/{$write->id}")
            ->assertStatus(200)->assertJsonPath('data.status', 'ok');
    }

    public function test_module_gate_blocks_other_company(): void
    {
        $this->actingAs($this->autoUser, 'sanctum')->putJson(
            "/api/v1/companies/{$this->auto->id}/integrations/pingwin/documents/1226", ['fields' => ['description' => 'x']]
        )->assertStatus(403);
    }

    /** D2a — o endpoint aceita mudanças de filhas e só guarda as 9 permitidas. */
    public function test_update_accepts_children_and_whitelists_disallowed(): void
    {
        $this->seedDoc(true);
        Bus::fake();
        $resp = $this->actingAs($this->restoUser, 'sanctum')->putJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/1226",
            ['children' => [
                'docconfig_docstatus' => [['id' => '8011', 'deleted' => 0], ['id' => '8004', 'deleted' => 1]],
                'docconfig_paymethod' => [['id' => '16001', 'deleted' => 0]],
                'userrole_docconfig'  => [['id' => 'x', 'deleted' => 1]],   // NÃO permitida (D2a)
                'docconfig_docaccount' => [['id' => '13000', 'deleted' => 1]], // NÃO permitida (D2b)
            ]]
        )->assertStatus(200)->assertJsonPath('data.status', 'a_criar');

        $write = PingwinDocconfigWrite::find($resp->json('data.write_id'));
        $this->assertArrayHasKey('docconfig_docstatus', $write->children);
        $this->assertArrayHasKey('docconfig_paymethod', $write->children);
        $this->assertArrayNotHasKey('userrole_docconfig', $write->children);     // excluída
        $this->assertArrayNotHasKey('docconfig_docaccount', $write->children);    // D2b
        $this->assertCount(2, $write->children['docconfig_docstatus']);
        Bus::assertDispatched(UpdatePingwinDocumentConfigJob::class);
    }

    /** D2a — após persisted, o job refresca as colunas JSON das filhas a partir do reread. */
    public function test_job_refreshes_child_columns_after_confirmation(): void
    {
        $this->seedDoc(true);
        $result = [
            'ok' => true, 'persisted' => true, 'pingwin_id' => '1226',
            'raw' => [
                'maindataset' => [['code' => 'FTAF', 'description' => 'Auto Fatura UP', 'deleted' => 0]],
                // reread com docstatus editado: 8011 vinculado (0), 8004 desvinculado (1)
                'docconfig_docstatus' => [
                    ['docstatus_id' => '8001', 'deleted' => 0], ['docstatus_id' => '8004', 'deleted' => 1],
                    ['docstatus_id' => '8011', 'deleted' => 0],
                ],
                'docconfig_paycond' => [['paycond_id' => '16010', 'deleted' => 0]],
                'additionalfields.maindataset' => [['ignore_pending_qnt' => 0]],
                '_storedataset' => [['store' => '1']],
            ],
        ];
        $this->app->instance(PingwinService::class, $this->fakeService($result));

        $write = PingwinDocconfigWrite::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id, 'action' => 'editar',
            'docconfig_id' => '1226', 'code' => 'FTAF', 'description' => 'Auto Fatura UP',
            'fields' => [], 'children' => ['docconfig_docstatus' => [['id' => '8011', 'deleted' => 0], ['id' => '8004', 'deleted' => 1]]],
            'status' => 'a_criar',
        ]);
        UpdatePingwinDocumentConfigJob::dispatchSync($this->resto->id, $write->id);

        $this->assertSame('ok', $write->fresh()->status);
        $d = PingwinDocumentConfig::where('company_id', $this->resto->id)->where('external_id', '1226')->first();
        $d->makeVisible(PingwinDocumentConfig::RICH_JSON_COLUMNS);
        $linked = collect($d->docconfig_docstatus)->filter(fn ($r) => (int) ($r['deleted'] ?? 0) === 0)->pluck('docstatus_id')->map(fn ($v) => (string) $v)->values()->all();
        $this->assertEqualsCanonicalizing(['8001', '8011'], $linked);   // 8011 vinculado, 8004 fora
        $this->assertSame('1', (string) ($d->additionalfields_storedataset[0]['store'] ?? null));
    }

    /** D2b — o endpoint aceita estados coerentes do docaccount e REJEITA incoerências. */
    public function test_docaccount_coherent_accepted_incoherent_rejected(): void
    {
        $this->seedDoc(true);
        Bus::fake();
        // coerentes: crédito + não usada
        $resp = $this->actingAs($this->restoUser, 'sanctum')->putJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/1226",
            ['docaccount' => [
                ['docaccount_id' => '13005', 'deleted' => 0, 'credit' => 1, 'debit' => 0],
                ['docaccount_id' => '13007', 'deleted' => 1, 'credit' => 0, 'debit' => 0],
            ]]
        )->assertStatus(200);
        $write = PingwinDocconfigWrite::find($resp->json('data.write_id'));
        $this->assertCount(2, $write->docaccount);
        Bus::assertDispatched(UpdatePingwinDocumentConfigJob::class);

        // incoerente: deleted=0 com credit=0 e debit=0 → 422
        $this->actingAs($this->restoUser, 'sanctum')->putJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/1226",
            ['docaccount' => [['docaccount_id' => '13005', 'deleted' => 0, 'credit' => 0, 'debit' => 0]]]
        )->assertStatus(422);

        // incoerente: ambos=1 → 422
        $this->actingAs($this->restoUser, 'sanctum')->putJson(
            "/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/1226",
            ['docaccount' => [['docaccount_id' => '13005', 'deleted' => 0, 'credit' => 1, 'debit' => 1]]]
        )->assertStatus(422);
    }

    /** D2b — após persisted, o job refresca a coluna docconfig_docaccount do reread. */
    public function test_job_reflects_docaccount_after_confirmation(): void
    {
        $this->seedDoc(true);
        $result = [
            'ok' => true, 'persisted' => true, 'pingwin_id' => '1226',
            'raw' => [
                'maindataset' => [['code' => 'FTAF', 'description' => 'Auto Fatura UP', 'deleted' => 0]],
                'docconfig_docaccount' => [
                    ['docaccount_id' => '13000', 'description' => 'CC', 'deleted' => 0, 'credit' => 1, 'debit' => 0],
                    ['docaccount_id' => '13005', 'description' => 'Banco', 'deleted' => 0, 'credit' => 1, 'debit' => 0],
                    ['docaccount_id' => '13007', 'description' => 'Compras', 'deleted' => 1, 'credit' => 0, 'debit' => 0],
                ],
            ],
        ];
        $this->app->instance(PingwinService::class, $this->fakeService($result));

        $write = PingwinDocconfigWrite::create([
            'company_id' => $this->resto->id, 'user_id' => $this->restoUser->id, 'action' => 'editar',
            'docconfig_id' => '1226', 'code' => 'FTAF', 'description' => 'Auto Fatura UP',
            'fields' => [], 'children' => [],
            'docaccount' => [['docaccount_id' => '13005', 'deleted' => 0, 'credit' => 1, 'debit' => 0],
                             ['docaccount_id' => '13007', 'deleted' => 1, 'credit' => 0, 'debit' => 0]],
            'status' => 'a_criar',
        ]);
        UpdatePingwinDocumentConfigJob::dispatchSync($this->resto->id, $write->id);

        $this->assertSame('ok', $write->fresh()->status);
        $d = PingwinDocumentConfig::where('company_id', $this->resto->id)->where('external_id', '1226')->first();
        $d->makeVisible(PingwinDocumentConfig::RICH_JSON_COLUMNS);
        $byId = collect($d->docconfig_docaccount)->keyBy('docaccount_id');
        $this->assertSame(1, (int) $byId['13005']['credit']);   // Banco → crédito
        $this->assertSame(1, (int) $byId['13007']['deleted']);  // Compras → não usada
        $this->assertSame(1, (int) $byId['13000']['credit']);   // CC preservada (crédito)
    }
}
