<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CloseSupplierDocumentsJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\OcrInvoice;
use App\Models\OcrInvoicePingwinLink;
use App\Models\PingwinCatalogItem;
use App\Models\ProductUnitProfile;
use App\Models\SupplierDocumentClose;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Finance\SupplierDocumentCloseService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Fechar no PingWin" documentos de fornecedor em Aberto (também os lançados diretamente no PingWin).
 * PingWin simulado. A conferência pelo espelho (linhas, total com acerto até 0,05 €, unidades
 * confirmadas), o lote de no máximo 10, um de cada vez com a leitura viva antes e a releitura depois,
 * parar no primeiro erro, o registo (quem, quando, estado antes e depois) e o filtro "Em aberto".
 */
class FinanceCloseDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    public array $calls = [];
    public array $live = [];

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500013400', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op']]);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        foreach (['PA' => 'ARTIGO A', 'PB' => 'ARTIGO B'] as $id => $d) {
            PingwinCatalogItem::create(['company_id' => $this->company->id, 'pingwin_id' => $id, 'code' => $id, 'description' => $d, 'is_active' => true]);
        }
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
            protected function invoke(array $payload): array
            {
                throw new \LogicException('Chamada real ao PingWin num teste: ' . ($payload['mode'] ?? '?'));
            }
            public function fetchSupplierDocumentLines(int $companyId, array $docs): array
            {
                $this->test->calls[] = ['read', $docs[0]['docheader_id']];

                return array_map(fn ($d) => $this->test->live[$d['docheader_id']], $docs);
            }
            public function documentStatus(int $companyId, string $docheaderId, string $status, string $docconfigId = '1209'): array
            {
                $this->test->calls[] = ['status', $docheaderId, $status];

                return ['result' => ['ok' => true, 'docstatus_id' => $status], 'reread' => ['ok' => true, 'header' => ['docstatus_id' => $status], 'details' => []]];
            }
        });
    }

    /** Um documento em Aberto com linhas no espelho e a leitura viva igual. */
    private function openDoc(string $id, array $lines = [['PA', 1000, 230], ['PB', 500, 115]], ?int $tax = null): void
    {
        $net = array_sum(array_column($lines, 1));
        $tax ??= array_sum(array_column($lines, 2));
        DB::table('pingwin_supplier_documents')->insert(['company_id' => $this->company->id, 'docheader_id' => $id, 'docconfig_id' => '1209', 'doctype' => 'Fatura de fornecedor',
            'document' => "VFT/{$id}", 'entity_pingwin_id' => 'E1', 'entity_name' => 'Fornecedor', 'doc_date' => '2026-09-10', 'total_cents' => $net + $tax, 'total_tax_cents' => $tax,
            'paid' => false, 'docstatus_id' => '8001', 'docstatus_description' => 'Aberto', 'lines_status' => $lines ? 'ok' : null, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as $i => [$p, $c, $t]) {
            DB::table('pingwin_supplier_document_lines')->insert(['company_id' => $this->company->id, 'docheader_id' => $id, 'line_number' => $i + 1, 'product_pingwin_id' => $p,
                'total_cents' => $c, 'tax_value_cents' => $t, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->live[$id] = ['docheader_id' => $id, 'ok' => true, 'header' => ['docstatus_id' => '8001', 'total' => ($net + $tax) / 100, 'total_tax' => $tax / 100],
            'details' => array_map(fn ($l) => ['product_id' => $l[0], 'total' => $l[1] / 100, 'tax_value' => $l[2] / 100], $lines)];
    }

    private function confirm(array $ids = ['PA', 'PB']): void
    {
        foreach ($ids as $id) {
            ProductUnitProfile::create(['company_id' => $this->company->id, 'product_pingwin_id' => $id, 'state' => 'confirmado']);
        }
    }

    private function svc(): SupplierDocumentCloseService
    {
        return app(SupplierDocumentCloseService::class);
    }

    private function url(string $s = ''): string
    {
        return "/api/v1/companies/{$this->company->id}/integrations/pingwin/supplier-documents{$s}";
    }

    public function test_the_mirror_check_lines_total_within_five_cents_and_confirmed_units(): void
    {
        $this->openDoc('D1');
        $this->openDoc('D2', []);                                  // sem linhas lidas
        $this->openDoc('D3', [['PA', 1000, 230]], 236);            // o total sem IVA fica 6 cêntimos acima das linhas
        DB::table('pingwin_supplier_documents')->where('docheader_id', 'D3')->update(['total_cents' => 1000 + 236 + 6]);
        $docs = \App\Models\PingwinSupplierDocument::where('company_id', $this->company->id)->get();

        $keys = function () use ($docs) {
            $k = array_map(fn ($p) => array_column($p, 'key'), $this->svc()->checks($this->company->id, $docs));
            ksort($k);

            return $k;
        };
        $this->assertSame(['D1' => ['unidades'], 'D2' => ['linhas'], 'D3' => ['total', 'unidades']], $keys());
        $this->assertStringContainsString('ARTIGO A, ARTIGO B', $this->svc()->checks($this->company->id, $docs)['D1'][0]['message']);
        $this->confirm();
        $this->assertSame(['D1' => [], 'D2' => ['linhas'], 'D3' => ['total']], $keys());
        // Até 0,05 € de diferença passa.
        DB::table('pingwin_supplier_documents')->where('docheader_id', 'D3')->update(['total_cents' => 1000 + 236 + 5]);
        $this->assertSame([], $this->svc()->checks($this->company->id, \App\Models\PingwinSupplierDocument::where('docheader_id', 'D3')->get())['D3']);

        // O filtro "Em aberto" da lista de documentos traz o que impede fechar cada um.
        $rows = collect($this->actingAs($this->user, 'sanctum')->getJson($this->url('?open=1'))->assertOk()->json('data.documents.data'))->keyBy('docheader_id');
        $this->assertSame([], $rows['D1']['close_problems']);
        $this->assertSame('linhas', $rows['D2']['close_problems'][0]['key']);
    }

    public function test_a_blocked_document_is_not_closed_and_says_why_and_the_batch_has_at_most_ten(): void
    {
        Bus::fake([CloseSupplierDocumentsJob::class]);
        $this->openDoc('D1');
        $this->actingAs($this->user, 'sanctum')->postJson($this->url('/close'), ['docheader_ids' => ['D1']])
            ->assertStatus(422)->assertJsonPath('errors.failed.0.key', 'unidades')
            ->assertJsonPath('message', 'Não é possível fechar VFT/D1: Falta confirmar as unidades no Guião de unidades: ARTIGO A, ARTIGO B.');
        $this->actingAs($this->user, 'sanctum')->postJson($this->url('/close'), ['docheader_ids' => array_map(fn ($i) => "X{$i}", range(1, 11))])->assertStatus(422);
        $this->assertSame(0, SupplierDocumentClose::count());
        Bus::assertNotDispatched(CloseSupplierDocumentsJob::class);
    }

    public function test_close_the_selected_one_at_a_time_with_live_read_and_reread_and_record_it(): void
    {
        Bus::fake([CloseSupplierDocumentsJob::class]);
        $this->confirm();
        $this->openDoc('D1');
        $this->openDoc('D2');
        $inv = OcrInvoice::create(['company_id' => $this->company->id, 'image_path' => 'x.pdf', 'status' => 'validada']);
        OcrInvoicePingwinLink::create(['company_id' => $this->company->id, 'ocr_invoice_id' => $inv->id, 'docheader_id' => 'D2', 'method' => 'numero']);

        $res = $this->actingAs($this->user, 'sanctum')->postJson($this->url('/close'), ['docheader_ids' => ['D1', 'D2']])->assertStatus(202);
        $this->assertSame('A fechar 2 documentos no PingWin, um de cada vez…', $res->json('message'));
        $batch = $res->json('data.batch');
        Bus::assertDispatched(CloseSupplierDocumentsJob::class);
        $this->svc()->execute($this->company->id, $batch);

        $this->assertSame([['read', 'D1'], ['status', 'D1', '8002'], ['read', 'D2'], ['status', 'D2', '8002']], $this->calls);
        $rows = $this->actingAs($this->user, 'sanctum')->getJson($this->url("/close-batches/{$batch}"))->assertOk()->assertJsonPath('data.done', true)->json('data.closes');
        $this->assertSame([['ok', '8001', '8002', $this->user->name], ['ok', '8001', '8002', $this->user->name]],
            array_map(fn ($r) => [$r['status'], $r['status_before'], $r['status_after'], $r['user']], $rows));
        $this->assertSame(['8002', '8002'], DB::table('pingwin_supplier_documents')->orderBy('docheader_id')->pluck('docstatus_id')->all());
        $this->assertSame($this->user->id, $inv->fresh()->closed_by, 'a fatura carregada ligada fica com o fecho registado');
    }

    public function test_stops_at_the_first_error_and_never_closes_a_document_that_is_no_longer_open_live(): void
    {
        Bus::fake([CloseSupplierDocumentsJob::class]);
        $this->confirm();
        $this->openDoc('D1');
        $this->openDoc('D2');
        $batch = $this->svc()->request($this->company->id, ['D1', 'D2'], $this->user->id)['batch'];
        $this->live['D1']['header']['docstatus_id'] = '8003'; // anulado no PingWin depois da última sincronização

        $this->svc()->execute($this->company->id, $batch);

        $this->assertSame([['read', 'D1']], $this->calls, 'nada foi gravado');
        $closes = SupplierDocumentClose::orderBy('position')->get();
        $this->assertSame(['erro', 'nao_feito'], $closes->pluck('status')->all());
        $this->assertStringContainsString('Só se fecha um documento em Aberto.', $closes[0]->error);
        $this->assertSame('8003', $closes[0]->status_before);
        $this->assertSame('Não foi feito: o fecho de VFT/D1 falhou.', $closes[1]->error);
        $this->assertSame(['8001', '8001'], DB::table('pingwin_supplier_documents')->orderBy('docheader_id')->pluck('docstatus_id')->all());
    }

    public function test_the_reread_must_show_closed(): void
    {
        Bus::fake([CloseSupplierDocumentsJob::class]);
        $this->confirm();
        $this->openDoc('D1');
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
            public function fetchSupplierDocumentLines(int $companyId, array $docs): array
            {
                return [$this->test->live['D1']];
            }
            public function documentStatus(int $companyId, string $docheaderId, string $status, string $docconfigId = '1209'): array
            {
                return ['result' => ['ok' => true], 'reread' => ['ok' => true, 'header' => ['docstatus_id' => '8001'], 'details' => []]];
            }
        });
        $batch = app(SupplierDocumentCloseService::class)->request($this->company->id, ['D1'], $this->user->id)['batch'];
        app(SupplierDocumentCloseService::class)->execute($this->company->id, $batch);
        $c = SupplierDocumentClose::first();
        $this->assertSame(['erro_confirmacao', '8001'], [$c->status, $c->status_after]);
        $this->assertSame('8001', DB::table('pingwin_supplier_documents')->value('docstatus_id'));
    }
}
