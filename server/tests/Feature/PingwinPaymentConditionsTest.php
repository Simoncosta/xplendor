<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncPingwinPaymentConditionsJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinPaymentCondition;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — PingWin Condições de Pagamento (Fatia 1, só leitura): guardar (UPSERT
 * idempotente), o CAMINHO COMPLETO do sync (Job → syncPaymentConditions → mock
 * Python → BD), conversões na fronteira (discount %, days int), tbdocs em JSON,
 * is_active (ativos vs anulados), lista paginada + pesquisa/filtro, gate do módulo
 * + tenancy. O Python é substituído por um fake.
 */
class PingwinPaymentConditionsTest extends TestCase
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

    private function fakeService(array $conditions): PingwinService
    {
        return new class($conditions) extends PingwinService {
            public array $seen = [];
            public function __construct(private array $conds) {}
            protected function invoke(array $payload): array
            {
                $this->seen = $payload;
                return ['ok' => true, 'mode' => 'paycond', 'payment_conditions' => $this->conds];
            }
        };
    }

    /**
     * Shape do que o fetch_payment_conditions (Python) devolve: id/code/description/
     * deleted (da lista) + discount/days/tbdocs (do detalhe). As 3 reais do Yuko +
     * a de teste. Inclui aliases (financialdiscount) e um anulado (deleted:1).
     */
    private function sampleConditions(): array
    {
        return [
            // "Fim do mês" (code 0): 30 dias, sem desconto, com 2 documentos vinculados.
            ['id' => '584955579000000001', 'code' => '0', 'description' => 'Fim do mês', 'deleted' => 0,
             'discount' => '0', 'days' => 30, 'tbdocs' => [
                 ['docconfig_id' => '1209', 'description' => 'Fatura de fornecedor', 'entitytype' => 'Fornecedor', 'deleted' => 0],
                 ['docconfig_id' => '1201', 'description' => 'Fatura', 'entitytype' => 'Cliente', 'deleted' => 0],
             ]],
            // "30 dias" (code 1): desconto 2.5% (via alias financialdiscount).
            ['id' => '584955579000000002', 'code' => '1', 'description' => '30 dias', 'deleted' => 0,
             'financialdiscount' => '2.5', 'days' => 30, 'tbdocs' => []],
            // "Pronto Pagamento" (code 3): 0 dias, desconto 5%.
            ['id' => '584955579000000003', 'code' => '3', 'description' => 'Pronto Pagamento', 'deleted' => 0,
             'discount' => '5', 'days' => 0, 'tbdocs' => []],
            // "XPLENDOR Teste" (o registo descartável) — anulado (STATE 1).
            ['id' => '584955579139782582', 'code' => '99', 'description' => 'XPLENDOR Teste', 'deleted' => 1],
        ];
    }

    public function test_sync_persists_conditions_with_conversions_and_tbdocs(): void
    {
        $fake = $this->fakeService($this->sampleConditions());
        $count = $fake->syncPaymentConditions($this->resto->id);

        $this->assertSame(4, $count);
        $this->assertSame('paycond', $fake->seen['mode']);
        $this->assertSame(4, PingwinPaymentCondition::where('company_id', $this->resto->id)->count());

        // "Fim do mês": days int, discount 0, tbdocs com 2 documentos (docconfig_id 1209).
        $fim = PingwinPaymentCondition::where('pingwin_id', '584955579000000001')->first();
        $this->assertSame('0', $fim->code);
        $this->assertSame('Fim do mês', $fim->description);
        $this->assertSame(30, $fim->days);
        $this->assertSame('0.00', (string) $fim->discount);   // % (decimal:2), NÃO cêntimos
        $this->assertTrue($fim->is_active);
        $this->assertIsArray($fim->tbdocs);
        $this->assertCount(2, $fim->tbdocs);
        $this->assertSame('1209', (string) $fim->tbdocs[0]['docconfig_id']);
        $this->assertSame('Fornecedor', $fim->tbdocs[0]['entitytype']);

        // "30 dias": desconto 2.5% via alias financialdiscount.
        $trinta = PingwinPaymentCondition::where('pingwin_id', '584955579000000002')->first();
        $this->assertSame('2.50', (string) $trinta->discount);
        $this->assertSame(30, $trinta->days);

        // "Pronto Pagamento": 0 dias, 5%.
        $pronto = PingwinPaymentCondition::where('pingwin_id', '584955579000000003')->first();
        $this->assertSame('5.00', (string) $pronto->discount);
        $this->assertSame(0, $pronto->days);

        // Anulado (deleted:1) → inativo.
        $teste = PingwinPaymentCondition::where('pingwin_id', '584955579139782582')->first();
        $this->assertFalse($teste->is_active);
        $this->assertSame('XPLENDOR Teste', $teste->description);
    }

    public function test_sync_is_idempotent_upsert(): void
    {
        $this->fakeService($this->sampleConditions())->syncPaymentConditions($this->resto->id);
        $changed = $this->sampleConditions();
        $changed[1]['financialdiscount'] = '3.0';
        $this->fakeService($changed)->syncPaymentConditions($this->resto->id);

        $this->assertSame(4, PingwinPaymentCondition::where('company_id', $this->resto->id)->count());
        $this->assertSame('3.00', (string) PingwinPaymentCondition::where('pingwin_id', '584955579000000002')->first()->discount);
    }

    /**
     * CAMINHO COMPLETO ponta-a-ponta: o JOB resolve o PingwinService REAL do
     * container (só o Python/invoke é substituído) e corre handle(). Prova que
     * Job → syncPaymentConditions (método existe!) → guarda BD funciona.
     */
    public function test_job_runs_full_chain(): void
    {
        $this->app->instance(PingwinService::class, $this->fakeService($this->sampleConditions()));

        SyncPingwinPaymentConditionsJob::dispatchSync($this->resto->id);

        $this->assertSame(4, PingwinPaymentCondition::where('company_id', $this->resto->id)->count());
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Condições de pagamento atualizadas']);
    }

    public function test_list_endpoint_paginates_searches_and_filters(): void
    {
        $this->fakeService($this->sampleConditions())->syncPaymentConditions($this->resto->id);
        $base = "/api/v1/companies/{$this->resto->id}/integrations/pingwin/payment-conditions";

        // Paginação de exibição (Laravel).
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?perPage=2&page=1")
            ->assertStatus(200)
            ->assertJsonPath('data.payment_conditions.current_page', 1)
            ->assertJsonPath('data.payment_conditions.last_page', 2)
            ->assertJsonPath('data.payment_conditions.total', 4)
            ->assertJsonCount(2, 'data.payment_conditions.data');

        // Pesquisa por descrição.
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?search=Pronto")
            ->assertStatus(200)
            ->assertJsonPath('data.payment_conditions.total', 1)
            ->assertJsonPath('data.payment_conditions.data.0.description', 'Pronto Pagamento');

        // Filtro estado=inativo → só o XPLENDOR Teste (anulado).
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?active=0")
            ->assertStatus(200)
            ->assertJsonPath('data.payment_conditions.total', 1)
            ->assertJsonPath('data.payment_conditions.data.0.pingwin_id', '584955579139782582');
    }

    public function test_sync_endpoint_queues_job(): void
    {
        Bus::fake();
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/payment-conditions/sync")
            ->assertStatus(200)->assertJsonPath('data.queued', true);
        Bus::assertDispatched(SyncPingwinPaymentConditionsJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_module_gate_and_tenancy(): void
    {
        $this->actingAs($this->autoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->auto->id}/integrations/pingwin/payment-conditions")
            ->assertStatus(403);
    }
}
