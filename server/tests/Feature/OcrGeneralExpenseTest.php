<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\LaunchPingwinDocumentJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\GeneralExpenseSupplier;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\OcrInvoiceSummary;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinSupplier;
use App\Models\PingwinUnit;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\OcrInvoiceLaunchService;
use App\Services\OcrLineArticleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Faturas de despesas gerais (opção A1 do spike dos CTT): com o fornecedor marcado, as linhas com
 * valor ligam-se ao artigo de despesa da sua taxa e lançam-se numa linha por taxa (quantidade 1); as
 * linhas a 0,00 € não contam; a falta de quantidade não bloqueia; a taxa sem artigo fica por ligar,
 * com o aviso. Um fornecedor sem marca fica como hoje. Só o root marca. PingWin simulado: qualquer
 * chamada real falha.
 */
class OcrGeneralExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $root;
    private User $admin;
    private User $otherAdmin;
    private PingwinSupplier $ctt;
    private PingwinCatalogItem $exempt;
    private PingwinCatalogItem $normal;
    public array $calls = [];
    public ?array $launchResult = null;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Restaurante Exemplo Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000001', 'fiscal_name' => 'Outra Exemplo Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->company, $this->other] as $c) {
            app(CompanyModuleService::class)->applyPreset($c->id, 'restaurant');
            CompanyIntegration::create(['company_id' => $c->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op']]);
        }
        $this->root = User::factory()->create(['company_id' => $this->company->id, 'role' => 'root']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->otherAdmin = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        $this->ctt = PingwinSupplier::create(['company_id' => $this->company->id, 'source' => 'pingwin', 'pingwin_id' => 'SUPCTT', 'name' => 'CORREIOS EXEMPLO',
            'tax_number' => '123456783', 'is_active' => true]);
        PingwinUnit::create(['company_id' => $this->company->id, 'pingwin_id' => '11001', 'description' => 'Unidade', 'shortname' => 'UN', 'is_active' => true]);
        $this->exempt = $this->article($this->company, 'PDG0', 'DESPESAS GERAIS ISENTO');
        $this->normal = $this->article($this->company, 'PDG23', 'DESPESAS GERAIS 23%');

        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
            protected function invoke(array $payload): array
            {
                throw new \LogicException('Chamada real ao PingWin num teste: ' . ($payload['mode'] ?? '?'));
            }
            public function findSuppliersByNif(int $companyId, string $nif): array
            {
                $this->test->calls[] = ['find', $nif];

                return ['active' => [['id' => 'SUPCTT', 'tax_number' => '123456783']], 'voided' => []];
            }
            public function fetchSupplierDocuments(int $companyId, string $from, string $to): array
            {
                $this->test->calls[] = ['docs', $from, $to];

                return ['documents' => [], 'blocks' => 1, 'splits' => 0];
            }
            public function launchSupplierInvoice(int $companyId, array $doc): array
            {
                $this->test->calls[] = ['launch', $doc];

                return $this->test->launchResult;
            }
        });
    }

    // ───────────────────────────────────────────────────────────── helpers

    private function article(Company $c, string $pingwinId, string $description): PingwinCatalogItem
    {
        return PingwinCatalogItem::create(['company_id' => $c->id, 'pingwin_id' => $pingwinId, 'code' => $pingwinId, 'description' => $description,
            'is_active' => true, 'product_status' => 'Ativo', 'base_unit_id' => '11001', 'default_purchase_unit_id' => '11001', 'purchaseunit' => 'Unidade']);
    }

    private function url(string $suffix, ?Company $c = null): string
    {
        return '/api/v1/companies/' . ($c ?? $this->company)->id . $suffix;
    }

    private function mark(array $articles): void
    {
        $this->actingAs($this->root, 'sanctum')->putJson($this->url("/integrations/pingwin/suppliers/{$this->ctt->id}/general-expense"), ['enabled' => true, 'articles' => $articles])
            ->assertOk()->assertJsonPath('data.enabled', true);
        $this->app['auth']->forgetGuards();
    }

    /**
     * A fatura 2473 dos CTT (os números, sem nomes): 7 linhas isentas; 2 × "C.VERDE XS/DL NAC" a 3,00 €
     * e duas cartas registadas, cada uma com o código de seguimento a 0,00 € e duas linhas sem
     * quantidade (2,76 € e 1,79 €). Total 12,10 €. As linhas chegam por ligar, como depois da leitura.
     */
    private function cttInvoice(array $extra = [], ?Company $c = null): OcrInvoice
    {
        $c ??= $this->company;
        $rows = [['C.VERDE XS/DL NAC', 2, '1.500000', 300, 0], ['Correio Verde RL000000001PT', 1, '0.000000', 0, 0], ['Serv. Especial de Registo', null, null, 276, 0],
            ['Aviso Recep.', null, null, 179, 0], ['Correio Verde RL000000002PT', 1, '0.000000', 0, 0], ['Serv. Especial de Registo', null, null, 276, 0],
            ['Aviso Recep.', null, null, 179, 0], ...$extra];
        $byRate = collect($rows)->groupBy(4)->map(fn ($g, $rate) => ['rate' => (int) $rate, 'base_cents' => (int) $g->sum(3),
            'vat_cents' => (int) round($g->sum(3) * (int) $rate / 100)])->values()->all();
        $total = array_sum(array_map(fn ($r) => $r['base_cents'] + $r['vat_cents'], $byRate));
        $inv = OcrInvoice::create([
            'company_id' => $c->id, 'image_path' => 'x.pdf', 'status' => 'por_validar', 'supplier_nif' => '123456783', 'supplier_name' => 'CORREIOS EXEMPLO',
            'buyer_nif' => $c->nipc, 'number' => 'FT TESTE/2473', 'issue_date' => '2026-10-05', 'doc_type' => 'FT', 'qr_ok' => true, 'check_status' => 'confere',
            'link_status' => 'nao_lancada', 'qr_data' => ['fields' => ['O' => number_format($total / 100, 2, '.', '')], 'by_rate' => $byRate],
        ]);
        OcrInvoiceSummary::create(['ocr_invoice_id' => $inv->id, 'company_id' => $c->id, 'total_cents' => $total]);
        foreach ($rows as $i => [$item, $q, $price, $cents, $rate]) {
            OcrInvoiceLine::create(['ocr_invoice_id' => $inv->id, 'company_id' => $c->id, 'position' => $i, 'item' => $item, 'quantity' => $q,
                'unit_price' => $price, 'line_total_cents' => $cents, 'vat_rate' => $rate, 'link_state' => 'por_ligar']);
        }

        return $inv->fresh();
    }

    /** Como na leitura (InvoiceOcrService) ou ao reabrir: as linhas voltam a ligar-se. */
    private function read(OcrInvoice $inv): OcrInvoice
    {
        app(OcrLineArticleService::class)->linkInvoice($inv->fresh());

        return $inv->fresh();
    }

    private function preview(OcrInvoice $inv): array
    {
        return app(OcrInvoiceLaunchService::class)->preview($inv->fresh());
    }

    private function failed(array $p): array
    {
        return collect($p['guards'])->where('ok', false)->pluck('key')->values()->all();
    }

    // ───────────────────────────────────────────────────────────── testes

    public function test_the_ctt_invoice_launches_as_one_line_of_12_10(): void
    {
        $this->mark(['0' => $this->exempt->id, '23' => $this->normal->id]);
        $inv = $this->read($this->cttInvoice());

        // As 5 linhas com valor ligadas ao artigo isento; as 2 a 0,00 € ficam de fora, sem ligação.
        $byValue = $inv->lines->sortBy('position')->map(fn ($l) => [$l->line_total_cents, $l->article_id, $l->link_method])->values()->all();
        $this->assertSame([[300, $this->exempt->id, 'despesa'], [0, null, null], [276, $this->exempt->id, 'despesa'], [179, $this->exempt->id, 'despesa'],
            [0, null, null], [276, $this->exempt->id, 'despesa'], [179, $this->exempt->id, 'despesa']], $byValue);

        $p = $this->preview($inv);
        $this->assertTrue($p['can_launch'], json_encode(collect($p['guards'])->where('ok', false)->values(), JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $p['planned']['lines']);
        $row = $p['planned']['lines'][0];
        $this->assertSame(['grupo', 5, 1.0, 12.1, 1210, 0, 'DESPESAS GERAIS ISENTO'], [$row['kind'], $row['lines'], $row['qnt'], $row['price'], $row['net_cents'], $row['vat_rate'], $row['article']['description']]);
        $this->assertSame([1210, 1210, 0], [$p['estimate']['computed_cents'], $p['estimate']['target_cents'], $p['estimate']['adjustment_cents']]);
        $this->assertSame(['lines' => 5, 'ignored_zero' => 2, 'missing_rates' => []], array_intersect_key($p['general_expense'], array_flip(['lines', 'ignored_zero', 'missing_rates'])));

        // Lança como hoje (1209), com a linha da taxa.
        $this->launchResult = [
            'result' => ['ok' => true, 'saved' => true, 'docheader_id' => 'DOC9', 'docconfig_id' => '1209', 'document' => 'VFT BOVFT/9', 'adjustment' => '0.00',
                'header_before_save' => ['id' => 'DOC9', 'doc_prefix' => 'VFT BOVFT', 'doc_number' => 9, 'store_code' => 'BO'], 'lines' => [['total' => 12.1, 'tax_description' => 'Isento']]],
            'reread' => ['ok' => true, 'header' => ['id' => 'DOC9', 'doc_prefix' => 'VFT BOVFT', 'doc_number' => 9, 'docstatus_id' => '8001', 'entity_id' => 'SUPCTT', 'total' => 12.1,
                'total_products' => 12.1, 'total_tax' => 0, 'docreference_number' => 'FT TESTE/2473', 'doc_date' => '20261010T00:00:00', 'store_id' => 'S1', 'detail_discount_value' => 0],
                'details' => [['line_number' => 1, 'product_id' => 'PDG0', 'total' => 12.1, 'tax_value' => 0, 'qnt' => 1, 'price' => 12.1]]],
        ];
        Bus::fake([LaunchPingwinDocumentJob::class]);
        $svc = app(OcrInvoiceLaunchService::class);
        $w = $svc->execute($svc->requestLaunch($inv, $this->admin->id, false)->id);
        $this->assertSame('ok', $w->status, (string) $w->error);
        $doc = collect($this->calls)->firstWhere(0, 'launch')[1];
        $this->assertCount(1, $doc['lines']);
        $this->assertSame(['PDG0', '11001', '1.000000', '12.100000', 0], [$doc['lines'][0]['product_id'], $doc['lines'][0]['unit_id'], $doc['lines'][0]['qnt'],
            $doc['lines'][0]['price'], $doc['lines'][0]['vat_rate']]);
        $this->assertSame('12.10', $doc['target_total']);
    }

    public function test_an_invoice_with_two_rates_goes_as_two_lines(): void
    {
        $this->mark(['0' => $this->exempt->id, '23' => $this->normal->id]);
        $inv = $this->read($this->cttInvoice([['Embalagem', 2, '1.000000', 200, 23], ['Fita', null, null, 150, 23]]));

        $p = $this->preview($inv);
        $this->assertTrue($p['can_launch'], json_encode(collect($p['guards'])->where('ok', false)->values(), JSON_UNESCAPED_UNICODE));
        $rows = collect($p['planned']['lines'])->map(fn ($r) => [$r['kind'], $r['article']['description'], $r['lines'], $r['net_cents'], $r['vat_rate']])->all();
        $this->assertSame([['grupo', 'DESPESAS GERAIS ISENTO', 5, 1210, 0], ['grupo', 'DESPESAS GERAIS 23%', 2, 350, 23]], $rows);
        $this->assertSame([[23, 2, 350], [0, 5, 1210]], collect($p['general_expense']['rates'])->map(fn ($r) => [$r['rate'], $r['lines'], $r['total_cents']])->all());
    }

    public function test_zero_lines_are_ignored_and_need_no_link(): void
    {
        $this->mark(['0' => $this->exempt->id]);
        $inv = $this->read($this->cttInvoice());

        $show = $this->actingAs($this->admin, 'sanctum')->getJson($this->url("/ocr/invoices/{$inv->id}"))->assertOk()->json('data.invoice');
        $this->assertSame([false, true, false, false, true, false, false], array_column($show['lines'], 'expense_ignored'));
        // "Pronta para lançar": as 5 com valor ligadas (as de 0,00 € não contam).
        $this->assertSame(['total' => 5, 'linked' => 5, 'suggested' => 0, 'unlinked' => 0, 'ready' => true], $show['articles_summary']);
        $this->assertSame([5, 2], [$show['general_expense']['lines'], $show['general_expense']['ignored_zero']]);
        $this->assertSame([], collect($this->preview($inv)['planned']['lines'])->where('kind', 'linha')->all());
    }

    public function test_a_rate_without_an_article_stays_unlinked_with_the_warning(): void
    {
        $this->mark(['0' => $this->exempt->id]);
        $inv = $this->read($this->cttInvoice([['Embalagem', 2, '1.000000', 200, 23]]));

        $line = $inv->lines->firstWhere('item', 'Embalagem');
        $this->assertSame([null, 'por_ligar'], [$line->article_id, $line->link_state]);
        $p = $this->preview($inv);
        $this->assertFalse($p['can_launch']);
        $this->assertContains('artigos', $this->failed($p));
        $g = collect($p['guards'])->firstWhere('key', 'despesa_taxas');
        $this->assertFalse($g['ok']);
        $this->assertStringContainsString('Falta o artigo de despesa da taxa 23 %', $g['message']);
        $this->assertSame([23], $p['general_expense']['missing_rates']);
    }

    public function test_a_supplier_without_the_mark_stays_as_today(): void
    {
        $inv = $this->read($this->cttInvoice());

        $this->assertSame(0, $inv->lines->where('link_method', 'despesa')->count());
        $this->assertSame(7, $inv->lines->where('link_state', 'por_ligar')->count());
        $p = $this->preview($inv);
        $this->assertNull($p['general_expense']);
        $this->assertContains('artigos', $this->failed($p));
        $this->assertContains('valores', $this->failed($p), 'sem a marca, as linhas sem quantidade continuam a bloquear');
        $this->assertNotContains('despesa_taxas', $this->failed($p));
        $this->assertCount(7, collect($p['planned']['lines'])->where('kind', 'linha'));
        $show = $this->actingAs($this->admin, 'sanctum')->getJson($this->url("/ocr/invoices/{$inv->id}"))->assertOk()->json('data.invoice');
        $this->assertNull($show['general_expense']);
        $this->assertSame(7, $show['articles_summary']['total']);
        $this->assertSame([false], array_values(array_unique(array_column($show['lines'], 'expense_ignored'))));
    }

    public function test_apply_to_an_invoice_already_read_also_changes_the_manual_links(): void
    {
        $inv = $this->cttInvoice();
        $inv->lines()->where('position', 0)->update(['article_id' => $this->normal->id, 'link_state' => 'ligada', 'link_method' => 'manual']);
        $this->mark(['0' => $this->exempt->id]);

        // Ao reabrir, a ligação manual fica; o "Aplicar a esta fatura" muda-a.
        $this->assertSame($this->normal->id, $this->read($inv)->lines->firstWhere('position', 0)->article_id);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url("/ocr/invoices/{$inv->id}/general-expense/apply"))->assertOk()
            ->assertJsonPath('data.result.despesa', 5)->assertJsonPath('data.general_expense.lines', 5)
            ->assertJsonPath('message', 'Despesa geral aplicada: 5 linhas ligadas.');
        $this->assertSame([$this->exempt->id, 'despesa'], [$inv->fresh()->lines->firstWhere('position', 0)->article_id, $inv->fresh()->lines->firstWhere('position', 0)->link_method]);

        // Já lançada: não se aplica.
        $inv->forceFill(['link_status' => 'lancada'])->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url("/ocr/invoices/{$inv->id}/general-expense/apply"))->assertStatus(422);
    }

    public function test_only_root_marks_and_each_company_is_isolated(): void
    {
        $put = fn (User $u, Company $c, int $supplierId, array $body) => $this->actingAs($u, 'sanctum')
            ->putJson($this->url("/integrations/pingwin/suppliers/{$supplierId}/general-expense", $c), $body);

        // O administrador da empresa vê, mas não marca.
        $put($this->admin, $this->company, $this->ctt->id, ['enabled' => true, 'articles' => ['0' => $this->exempt->id]])->assertStatus(403);
        $this->assertSame(0, GeneralExpenseSupplier::count());
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')->getJson($this->url("/integrations/pingwin/suppliers/{$this->ctt->id}/general-expense"))->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.articles.0', null);
        $this->app['auth']->forgetGuards();

        // O root não pode usar um artigo de outra empresa, nem um fornecedor de outra empresa.
        $foreign = $this->article($this->other, 'PDGX', 'DESPESAS DA OUTRA');
        $otherCtt = PingwinSupplier::create(['company_id' => $this->other->id, 'source' => 'pingwin', 'pingwin_id' => 'SUPCTT', 'name' => 'CORREIOS EXEMPLO',
            'tax_number' => '123456783', 'is_active' => true]);
        $put($this->root, $this->company, $this->ctt->id, ['enabled' => true, 'articles' => ['0' => $foreign->id]])->assertStatus(422)->assertJsonValidationErrors(['articles.0']);
        $put($this->root, $this->company, $otherCtt->id, ['enabled' => true, 'articles' => ['0' => $this->exempt->id]])->assertStatus(404);
        $put($this->root, $this->company, $this->ctt->id, ['enabled' => true, 'articles' => []])->assertStatus(422);
        $this->assertSame(0, GeneralExpenseSupplier::count());

        // Marcado nesta empresa, o mesmo fornecedor (NIF) na outra empresa fica como hoje.
        $put($this->root, $this->company, $this->ctt->id, ['enabled' => true, 'articles' => ['0' => $this->exempt->id]])->assertOk()
            ->assertJsonPath('data.articles.0.description', 'DESPESAS GERAIS ISENTO')->assertJsonPath('data.articles.23', null);
        $this->assertSame($this->root->id, (int) GeneralExpenseSupplier::first()->changed_by);
        $theirs = $this->read($this->cttInvoice([], $this->other));
        $this->assertSame(0, $theirs->lines->where('link_method', 'despesa')->count());
        $this->assertNull($this->preview($theirs)['general_expense']);

        // A outra empresa não chega à definição nem ao "Aplicar" desta.
        $mine = $this->read($this->cttInvoice());
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->otherAdmin, 'sanctum')->getJson($this->url("/integrations/pingwin/suppliers/{$this->ctt->id}/general-expense"))->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->otherAdmin, 'sanctum')->postJson($this->url("/ocr/invoices/{$mine->id}/general-expense/apply"))->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->otherAdmin, 'sanctum')->postJson($this->url("/ocr/invoices/{$mine->id}/general-expense/apply", $this->other))->assertStatus(404);

        // Desmarcar: as faturas seguintes voltam a ser como hoje.
        $this->app['auth']->forgetGuards();
        $put($this->root, $this->company, $this->ctt->id, ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertSame(0, GeneralExpenseSupplier::count());
    }
}
