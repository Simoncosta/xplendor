<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncPingwinDocumentConfigsRichJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinDocumentConfig;
use App\Models\PingwinPaymentCondition;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — PingWin Documentos LEITURA RICA (Fase D0): a sync rica guarda os _id-chave em
 * colunas + o maindataset em raw + as 14 filhas em JSON (incl. docaccount credit/debit e
 * docconfig_paycond) + additionalfields + options; o endpoint de DETALHE devolve a config
 * completa (makeVisible) e resolve as condições de pagamento vinculadas cruzando com o
 * espelho; a LISTA continua leve (colunas pesadas escondidas); gatilho rico enfileira job;
 * tenancy. O Python é mockado.
 */
class PingwinDocumentConfigRichTest extends TestCase
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

    private function fakeService(array $documents): PingwinService
    {
        return new class($documents) extends PingwinService {
            public function __construct(private array $docs) {}
            protected function invoke(array $payload): array
            {
                return ['ok' => true, 'mode' => 'documents_rich', 'documents' => $this->docs];
            }
        };
    }

    private function sampleDoc(): array
    {
        return [
            'id' => '1005', 'code' => 'FT', 'description' => 'Fatura', 'entitytype' => 'Cliente',
            'fiscaltype' => null, 'fiscaltype_description' => null, 'deleted' => false,
            'maindataset' => [
                'code' => 'FT', 'description' => 'Fatura', 'shortname' => 'FT',
                'taxscenario_id' => '3100', 'taxscenario_id_descr' => 'Venda',
                'doctype_id' => '17001', 'docfiscaltype_id' => '9500',
                'default_paycond_id' => '', 'stock_signal' => -1, 'docseries_id' => '4000', 'deleted' => 0,
            ],
            'options' => [
                'docfiscaltype' => array_fill(0, 53, ['id' => 'x', 'description' => 'y']),
                'taxscenario' => [['id' => '3100', 'description' => 'Venda'], ['id' => '3101', 'description' => 'Compra']],
                'doctype' => array_fill(0, 14, ['id' => 'd']),
            ],
            'children' => [
                'docconfig_paycond' => [
                    ['paycond_id' => '16010', 'description' => 'Fim do mes', 'deleted' => 0],
                    ['paycond_id' => '999999', 'description' => 'Fantasma', 'deleted' => 1],
                ],
                'docconfig_docaccount' => [
                    ['docaccount_id' => '13000', 'description' => 'CC', 'credit' => 0, 'debit' => 1, 'deleted' => 0],
                    ['docaccount_id' => '13001', 'description' => 'Caixa', 'credit' => 0, 'debit' => 0, 'deleted' => 1],
                ],
                'docconfig_docstatus' => array_fill(0, 5, ['id' => 's', 'deleted' => 0]),
            ],
            'additionalfields' => [
                'maindataset' => [['key1_id' => '1005', 'ignore_pending_qnt' => 0, 'changed' => 0]],
                'storedataset' => [['store' => '1'], ['store' => '2'], ['store' => '3']],
                'fieldsinfo' => [],
            ],
            'raw' => [
                'code' => 'FT', 'description' => 'Fatura', 'shortname' => 'FT',
                'taxscenario_id' => '3100', 'taxscenario_id_descr' => 'Venda', 'number_copies' => 2,
            ],
        ];
    }

    public function test_rich_sync_persists_columns_children_and_additionalfields(): void
    {
        PingwinPaymentCondition::create([
            'company_id' => $this->resto->id, 'pingwin_id' => '16010', 'code' => '0', 'description' => 'Fim do mes',
            'discount' => 0, 'days' => 0, 'is_active' => true, 'synced_at' => now(),
        ]);

        $count = $this->fakeService([$this->sampleDoc()])->syncDocumentConfigsRich($this->resto->id);
        $this->assertSame(1, $count);

        $d = PingwinDocumentConfig::where('company_id', $this->resto->id)->where('external_id', '1005')->first();
        $d->makeVisible(PingwinDocumentConfig::RICH_JSON_COLUMNS);
        $this->assertSame('3100', $d->taxscenario_id);     // _id (não o _descr)
        $this->assertSame('17001', $d->doctype_id);
        $this->assertSame('9500', $d->docfiscaltype_id);
        $this->assertSame('-1', $d->stock_signal);
        $this->assertSame('4000', $d->docseries_id);
        $this->assertSame('Venda', $d->raw['taxscenario_id_descr']);   // _descr fica no raw
        $this->assertCount(2, $d->docconfig_paycond);
        $this->assertCount(2, $d->docconfig_docaccount);
        // credit/debit preservados por linha
        $this->assertSame(1, $d->docconfig_docaccount[0]['debit']);
        $this->assertCount(53, $d->options['docfiscaltype']);
        $this->assertSame(0, $d->additionalfields_maindataset[0]['ignore_pending_qnt']);
        $this->assertCount(3, $d->additionalfields_storedataset);
        $this->assertNotNull($d->rich_synced_at);
    }

    public function test_detail_endpoint_returns_rich_and_resolves_paycond(): void
    {
        PingwinPaymentCondition::create([
            'company_id' => $this->resto->id, 'pingwin_id' => '16010', 'code' => '0', 'description' => 'Fim do mes',
            'discount' => 0, 'days' => 0, 'is_active' => true, 'synced_at' => now(),
        ]);
        $this->fakeService([$this->sampleDoc()])->syncDocumentConfigsRich($this->resto->id);

        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/1005")
            ->assertStatus(200)
            ->assertJsonPath('data.document.taxscenario_id', '3100')
            ->assertJsonPath('data.document.docconfig_paycond.0.paycond_id', '16010')
            // cruzamento: 16010 vinculado (deleted:0), no espelho e ativo; 999999 vinculado=false, fora do espelho.
            ->assertJsonPath('data.paycond_links.0.paycond_id', '16010')
            ->assertJsonPath('data.paycond_links.0.linked', true)
            ->assertJsonPath('data.paycond_links.0.in_mirror', true)
            ->assertJsonPath('data.paycond_links.0.is_active', true)
            ->assertJsonPath('data.paycond_links.1.linked', false)
            ->assertJsonPath('data.paycond_links.1.in_mirror', false);
    }

    public function test_list_endpoint_stays_light_hiding_heavy_json(): void
    {
        $this->fakeService([$this->sampleDoc()])->syncDocumentConfigsRich($this->resto->id);

        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents")
            ->assertStatus(200)
            ->assertJsonPath('data.documents.data.0.code', 'FT')
            ->assertJsonMissingPath('data.documents.data.0.docconfig_import')
            ->assertJsonMissingPath('data.documents.data.0.raw')
            ->assertJsonMissingPath('data.documents.data.0.options');
    }

    public function test_sync_rich_endpoint_queues_job(): void
    {
        Bus::fake();
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/sync-rich")
            ->assertStatus(200)->assertJsonPath('data.queued', true);
        Bus::assertDispatched(SyncPingwinDocumentConfigsRichJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_module_gate_blocks_company_without_pingwin(): void
    {
        // A empresa 'auto' não tem o módulo pingwin → o gate ensure_module bloqueia (403).
        $this->actingAs($this->autoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->auto->id}/integrations/pingwin/documents/1005")
            ->assertStatus(403);
    }
}
