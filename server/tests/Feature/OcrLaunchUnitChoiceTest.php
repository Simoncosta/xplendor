<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArticleGrouping;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\OcrInvoiceSummary;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinSupplier;
use App\Models\PingwinUnit;
use App\Models\User;
use App\Exceptions\LaunchBlocked;
use App\Services\CompanyModuleService;
use App\Services\OcrInvoiceLaunchService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Faturas (diagnóstico de 23/10, COMIDA STAFF 23%): a mensagem nomeia a unidade escolhida para a linha
 * (e, com nomes repetidos, qual aceita decimais) e oferece outra unidade do artigo; a escolha guardada
 * cai quando as unidades do artigo mudam, e uma escolha que arredonda cede à unidade lida na fatura
 * quando esta aceita decimais; a pré-visualização e o pedido de lançamento dão sempre a mesma resposta
 * (o agrupamento aplica-se antes das regras da unidade e do total). PingWin simulado.
 */
class OcrLaunchUnitChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private PingwinCatalogItem $staff;
    private PingwinCatalogItem $other;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Restaurante', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op']]);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        PingwinSupplier::create(['company_id' => $this->company->id, 'source' => 'pingwin', 'pingwin_id' => 'SUPX', 'name' => 'FORNECEDOR',
            'tax_number' => '123456783', 'is_active' => true]);
        // Duas unidades com o mesmo nome "Unidade": a 11001 não aceita decimais, a 11009 aceita.
        foreach ([['11001', 'Unidade', 'UN', 0], ['11009', 'Unidade', 'UN2', 1], ['11002', 'Quilograma', 'KG', 1]] as [$id, $d, $sh, $frac]) {
            PingwinUnit::create(['company_id' => $this->company->id, 'pingwin_id' => $id, 'description' => $d, 'shortname' => $sh, 'is_active' => true, 'raw' => ['frac_unit' => $frac]]);
        }
        $this->staff = PingwinCatalogItem::create(['company_id' => $this->company->id, 'pingwin_id' => 'PSTAFF', 'code' => '101208', 'description' => 'COMIDA STAFF 23%',
            'is_active' => true, 'product_status' => 'Ativo', 'base_unit_id' => '11001', 'default_purchase_unit_id' => '11001', 'default_stock_unit_id' => '11002']);
        $this->other = PingwinCatalogItem::create(['company_id' => $this->company->id, 'pingwin_id' => 'PB', 'code' => '902', 'description' => 'OUTRO',
            'is_active' => true, 'product_status' => 'Ativo', 'base_unit_id' => '11001', 'default_purchase_unit_id' => '11001']);
        $this->app->instance(PingwinService::class, new class extends PingwinService {
            public function __construct() {}
            protected function invoke(array $payload): array
            {
                throw new \LogicException('Chamada real ao PingWin num teste: ' . ($payload['mode'] ?? '?'));
            }
        });
    }

    /** O caso real (números da fatura, sem nomes): 3 linhas em kg no COMIDA STAFF escolhidas à Unidade e 1 linha de 174,10 €; total 409,16 €. */
    private function invoice(array $o = []): OcrInvoice
    {
        $inv = OcrInvoice::create(array_merge([
            'company_id' => $this->company->id, 'image_path' => 'x.pdf', 'status' => 'validada', 'supplier_nif' => '123456783', 'supplier_name' => 'FORNECEDOR',
            'buyer_nif' => '514148497', 'number' => 'FT ZCF2/1', 'issue_date' => '2026-10-20', 'doc_type' => 'FT', 'qr_ok' => true, 'check_status' => 'confere',
            'link_status' => 'nao_lancada', 'qr_data' => ['fields' => ['O' => '409.16'], 'by_rate' => [['rate' => 23, 'base_cents' => 33265, 'vat_cents' => 7651]]],
        ], $o));
        OcrInvoiceSummary::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->company->id, 'total_cents' => 40916]);
        foreach ([[8.09, '9.190000', 7437], [8.09, '2.490000', 2015], [8.665, '7.390000', 6403]] as $i => [$q, $price, $total]) {
            OcrInvoiceLine::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->company->id, 'position' => $i, 'item' => "Carne {$i}", 'quantity' => $q, 'unit' => 'KG',
                'unit_price' => $price, 'line_total_cents' => $total, 'gross_total_cents' => $total, 'vat_rate' => 23, 'article_id' => $this->staff->id,
                'link_state' => 'ligada', 'link_method' => 'mapa', 'launch_unit_id' => '11001']);
        }
        OcrInvoiceLine::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->company->id, 'position' => 3, 'item' => 'Outro', 'quantity' => 1, 'unit' => 'UN',
            'unit_price' => '174.100000', 'line_total_cents' => 17410, 'gross_total_cents' => 17410, 'vat_rate' => 23, 'article_id' => $this->other->id,
            'link_state' => 'ligada', 'link_method' => 'mapa']);

        return $inv->fresh();
    }

    private function svc(): OcrInvoiceLaunchService
    {
        return app(OcrInvoiceLaunchService::class);
    }

    private function guard(array $p, string $key): array
    {
        return collect($p['guards'])->firstWhere('key', $key);
    }

    public function test_the_message_names_the_chosen_unit_says_which_same_name_unit_takes_decimals_and_offers_another_unit_of_the_article(): void
    {
        // A unidade lida na fatura (KG) não é uma unidade do artigo com esse nome: a escolha (Unidade 11001) fica.
        PingwinUnit::where('pingwin_id', '11002')->update(['shortname' => 'KGR', 'description' => 'Quilo de carne']);
        $p = $this->svc()->preview($this->invoice());
        $g = $this->guard($p, 'unidades');
        $this->assertFalse($g['ok']);
        $first = explode(' Linha «Carne 1»', $g['message'])[0];
        $this->assertSame('Linha «Carne 0» (COMIDA STAFF 23%): a unidade escolhida para esta linha, Unidade (UN), não aceita decimais e a linha tem 8,09 kg; o PingWin arredonda para 9.'
            . ' Há mais do que uma unidade «Unidade»: a que aceita decimais é Unidade (UN2).'
            . ' Pode escolher outra unidade do artigo que aceita decimais: Quilo de carne (KGR).'
            . ' Se o artigo junta produtos diferentes, marque-o como artigo de agrupamento.', $first);
        $line = collect($p['lines'])->firstWhere('position', 0);
        $this->assertSame(['11001', 'escolhida', true], [$line['unit_id'], $line['unit_source'], $line['unit_rounds']]);
    }

    public function test_a_chosen_unit_that_rounds_gives_way_to_the_invoice_unit_when_it_takes_decimals(): void
    {
        // A linha diz KG e o artigo tem o Quilograma (KG), que aceita decimais: fica a da fatura.
        $p = $this->svc()->preview($this->invoice());
        $line = collect($p['lines'])->firstWhere('position', 0);
        $this->assertSame(['11002', 'fatura', false], [$line['unit_id'], $line['unit_source'], $line['unit_rounds']]);
        $this->assertTrue($this->guard($p, 'unidades')['ok']);
        $this->assertNull(collect($p['planned']['lines'])->firstWhere('line_id', $line['id'])['rounded_from']);
    }

    public function test_when_the_article_units_change_the_saved_choice_is_dropped_only_on_invoices_still_to_launch(): void
    {
        $open = $this->invoice();
        $launched = $this->invoice(['number' => 'FT ZCF2/2', 'link_status' => 'lancada']);
        $this->staff->update(['description' => 'COMIDA STAFF 23% (novo nome)']);
        $this->assertSame(3, OcrInvoiceLine::where('ocr_invoice_id', $open->id)->whereNotNull('launch_unit_id')->count(), 'outra mudança não toca na escolha');

        $this->staff->update(['default_purchase_unit_id' => '11002']);
        $this->assertSame(0, OcrInvoiceLine::where('ocr_invoice_id', $open->id)->whereNotNull('launch_unit_id')->count());
        $this->assertSame(3, OcrInvoiceLine::where('ocr_invoice_id', $launched->id)->whereNotNull('launch_unit_id')->count());
    }

    public function test_the_catalog_read_that_changes_a_unit_name_also_drops_the_saved_choice(): void
    {
        $open = $this->invoice();
        $svc = new class extends PingwinService {
            public array $articles = [];
            public function __construct() {}
            protected function invoke(array $payload): array
            {
                return ['ok' => true, 'articles' => $this->articles, 'deleted_ids' => []];
            }
        };
        $svc->articles = [['id' => 'PSTAFF', 'code' => '101208', 'description' => 'COMIDA STAFF 23%'],
            ['id' => 'PB', 'code' => '902', 'description' => 'OUTRO']];
        $svc->syncCatalog($this->company->id);
        $this->assertSame(3, OcrInvoiceLine::where('ocr_invoice_id', $open->id)->whereNotNull('launch_unit_id')->count(), 'sem mudança, a escolha fica');

        $svc->articles[0]['purchaseunit'] = 'Quilograma';
        $svc->syncCatalog($this->company->id);
        $this->assertSame(0, OcrInvoiceLine::where('ocr_invoice_id', $open->id)->whereNotNull('launch_unit_id')->count());
    }

    public function test_preview_on_screen_and_launch_request_always_agree_with_a_grouping_article_in_kg(): void
    {
        Bus::fake();
        // A escolha guardada à Unidade, e o Quilograma do artigo não é o KG lido: a regra da unidade falha.
        PingwinUnit::where('pingwin_id', '11002')->update(['shortname' => 'KGR', 'description' => 'Quilo de carne']);
        $inv = $this->invoice();
        $url = "/api/v1/companies/{$this->company->id}/ocr/invoices/{$inv->id}/pingwin-launch";

        $screen = $this->actingAs($this->user, 'sanctum')->getJson($url)->assertOk()->json('data');
        $this->assertFalse($screen['can_launch']);
        $this->assertSame(['unidades', 'acerto'], collect($screen['guards'])->where('ok', false)->pluck('key')->values()->all());
        $this->actingAs($this->user, 'sanctum')->postJson($url)->assertStatus(422)->assertJsonPath('errors.failed.0.key', 'unidades');

        // Marcado como artigo de agrupamento: o ecrã fica verde e o pedido passa (a mesma resposta).
        ArticleGrouping::create(['company_id' => $this->company->id, 'product_pingwin_id' => 'PSTAFF', 'is_grouping' => true]);
        $screen = $this->actingAs($this->user, 'sanctum')->getJson($url)->assertOk()->json('data');
        $this->assertTrue($screen['can_launch'], json_encode(collect($screen['guards'])->where('ok', false)->values()));
        $this->assertTrue($this->guard($screen, 'unidades')['ok']);
        $this->assertTrue($this->guard($screen, 'acerto')['ok']);
        $this->actingAs($this->user, 'sanctum')->postJson($url)->assertStatus(202);

        // E nunca ao contrário: uma pré-visualização vermelha recusa o pedido no serviço.
        $inv2 = $this->invoice(['number' => 'FT ZCF2/3', 'check_status' => 'nao_confere']);
        $this->assertFalse($this->svc()->preview($inv2)['can_launch']);
        $this->expectException(LaunchBlocked::class);
        $this->svc()->requestLaunch($inv2, $this->user->id, false);
    }
}
