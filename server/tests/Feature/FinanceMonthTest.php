<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FinanceClassification;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinDocumentConfig;
use App\Models\PingwinLocation;
use App\Models\PingwinSupplier;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Finance\MonthFinanceService;
use App\Services\SupplierCcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Finanças › Mês (setembro de 2026, hoje 09/10/2026): a família pelas linhas, pela família habitual
 * do fornecedor e "Por classificar" (e a classificação manual); cauções fora do resultado; pago (pela
 * nota de liquidação com o mesmo valor, ou estimado pela ordem das notas), pago no ato, por pagar e
 * vencido; o por pagar bate com a conta corrente, fornecedor a fornecedor; a frase-resumo; a API.
 */
class FinanceMonthTest extends TestCase
{
    use RefreshDatabase;

    private const FV = '1209';
    private const FR = '584955579139752236';
    private const NC = '1205';
    private const NL = '1204';

    private Company $company;
    private User $user;
    private array $sup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-09 12:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500013400', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $c = $this->company->id;

        foreach ([[self::FV, false], [self::FR, true], [self::NL, true], [self::NC, false]] as [$id, $settled]) {
            PingwinDocumentConfig::create(['company_id' => $c, 'external_id' => $id, 'code' => $id, 'description' => $id, 'deleted' => false, 'settled' => $settled, 'synced_at' => now()]);
        }
        // A árvore de famílias do PingWin (a raiz é "Família").
        foreach ([['F0', 'Família', null], ['F1', 'COMPRAS', 'F0'], ['F2', 'COMIDAS', 'F1'], ['F3', 'BEBIDAS ', 'F1'], ['F4', 'DIVERSOS', 'F1'],
            ['F5', 'Cauções de embalagens - fornecedores', 'F4'], ['F6', 'FORNECIMENTOS E SERV EXTERNOS', 'F0'], ['F7', 'RECURSOS HUMANOS', 'F0'],
            ['F8', 'OUTROS DEVEDORES/CREDORES', 'F0'], ['F9', 'PREDIAIS', 'F8'], ['F10', 'Rendas e alugueres', 'F9']] as [$id, $d, $p]) {
            DB::table('pingwin_families')->insert(['company_id' => $c, 'pingwin_id' => $id, 'description' => $d, 'parent_pingwin_id' => $p, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['A-CARNE', 'Lombo de porco', 'F2'], ['A-CERV', 'Cerveja barril', 'F3'], ['A-CAU', 'Caução barril', 'F5'], ['A-LUZ', 'Energias 23% iva', 'F6'],
            ['A-REN', 'Renda loja', 'F10'], ['A-DEP', 'VALOR DO DEPOSITO', 'F4']] as [$id, $d, $f]) {
            PingwinCatalogItem::create(['company_id' => $c, 'pingwin_id' => $id, 'code' => $id, 'description' => $d, 'family_pingwin_id' => $f, 'is_active' => true]);
        }
        foreach ([['E-CA', 'Carnes'], ['E-BE', 'Bebidas'], ['E-EDP', 'EDP'], ['E-REN', 'Senhorio'], ['E-NOVO', 'Fornecedor novo'], ['E-HAB', 'Talho habitual']] as [$pid, $name]) {
            $this->sup[$pid] = PingwinSupplier::create(['company_id' => $c, 'pingwin_id' => $pid, 'code' => $pid, 'name' => $name, 'is_active' => true, 'synced_at' => now()]);
        }

        // Agosto: o talho habitual vende carne (família habitual: Comida).
        $this->doc('D0', self::FV, 'E-HAB', '2026-08-15', 4920, 920, [['A-CARNE', 4000]]);
        // Setembro.
        $this->doc('D1', self::FV, 'E-CA', '2026-09-03', 12300, 2300, [['A-CARNE', 10000]], '2026-09-30');
        $this->doc('D2', self::FV, 'E-BE', '2026-09-05', 7150, 1150, [['A-CERV', 5000], ['A-CAU', 1000]], '2026-09-05');
        $this->doc('D3', self::NC, 'E-CA', '2026-09-10', 2460, 460, [['A-CARNE', 2000]], '2026-09-10');
        $this->doc('D4', self::FV, 'E-EDP', '2026-09-12', 24600, 4600, [['A-LUZ', 19999]], '2026-09-15');   // 1 cêntimo de acerto
        $this->doc('D5', self::FV, 'E-REN', '2026-09-04', 100000, 0, [['A-REN', 100000]], '2026-10-20');
        $this->doc('D6', self::FV, 'E-HAB', '2026-09-20', 5000, null, null);                                // sem linhas lidas
        $this->doc('D7', self::FV, 'E-NOVO', '2026-09-21', 3690, 690, [[null, 3000]], '2026-09-30');       // linha sem artigo
        $this->doc('D8', self::FR, 'E-BE', '2026-09-22', 2345, 345, [['A-DEP', 500], ['A-CERV', 1500]]);   // fatura-recibo: paga no ato
        $this->doc('D9', self::FV, 'E-BE', '2026-09-25', 6150, null, null, null, '8001', 'Aberto');         // em Aberto
        $this->doc('D10', self::FV, 'E-CA', '2026-09-26', 99900, 18680, [['A-CARNE', 81220]], null, '8003', 'Anulado');
        // Pagamentos (notas de liquidação) no espelho dos documentos.
        $this->doc('N1', self::NL, 'E-CA', '2026-09-28', 12300, null, null);
        $this->doc('N2', self::NL, 'E-BE', '2026-09-15', 4000, null, null);
        $this->doc('N3', self::NL, 'E-BE', '2026-10-02', 3150, null, null);

        // Vendas por artigo: setembro 5 000,00 € (2 dias), agosto 4 000,00 €.
        $loc = PingwinLocation::create(['company_id' => $c, 'winrest_store_id' => 'L1', 'display_name' => 'Baixa', 'is_active' => true]);
        foreach ([['2026-09-01', 300000, 39000], ['2026-09-02', 200000, 26000], ['2026-08-10', 400000, 52000]] as [$d, $net, $tax]) {
            DB::table('pingwin_item_sales_daily')->insert(['company_id' => $c, 'location_id' => $loc->id, 'business_date' => $d, 'product_pingwin_id' => 'P1',
                'product_name' => 'Prato', 'quantity' => 1, 'net_cents' => $net, 'tax_cents' => $tax, 'gross_cents' => $net + $tax, 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        // Conta corrente (S1).
        $cc = app(SupplierCcService::class);
        $cc->persistSupplier($c, $this->sup['E-CA'], ['ok' => true, 'balance' => ['balance' => -24.60], 'documents' => [
            $this->cc('D1', self::FV, 123.00, 0, 1, '2026-09-03', '2026-09-30'),
            $this->cc('N1', self::NL, 123.00, 0, -1, '2026-09-28', '2026-09-28'),
            $this->cc('D3', self::NC, 24.60, 24.60, -1, '2026-09-10', '2026-09-10'),
        ]]);
        $cc->persistSupplier($c, $this->sup['E-BE'], ['ok' => true, 'balance' => ['balance' => 23.45], 'documents' => [
            $this->cc('D2', self::FV, 71.50, 0, 1, '2026-09-05', '2026-09-05'),
            $this->cc('D8', self::FR, 23.45, 23.45, 1, '2026-09-22', '2026-09-22'),
            $this->cc('N2', self::NL, 40.00, 0, -1, '2026-09-15', '2026-09-15'),
            $this->cc('N3', self::NL, 31.50, 0, -1, '2026-10-02', '2026-10-02'),
        ]]);
        $cc->persistSupplier($c, $this->sup['E-EDP'], ['ok' => true, 'balance' => ['balance' => 246.00], 'documents' => [$this->cc('D4', self::FV, 246.00, 246.00, 1, '2026-09-12', '2026-09-15')]]);
        $cc->persistSupplier($c, $this->sup['E-REN'], ['ok' => true, 'balance' => ['balance' => 1000.00], 'documents' => [$this->cc('D5', self::FV, 1000.00, 1000.00, 1, '2026-09-04', '2026-10-20')]]);
        $cc->persistSupplier($c, $this->sup['E-NOVO'], ['ok' => true, 'balance' => ['balance' => 36.90], 'documents' => [$this->cc('D7', self::FV, 36.90, 36.90, 1, '2026-09-21', '2026-09-30')]]);
    }

    /** Um documento de fornecedor (F1) e as suas linhas (F4): [produto ou null, cêntimos]. */
    private function doc(string $id, string $cfg, string $entity, string $date, int $total, ?int $tax, ?array $lines, ?string $due = null, string $st = '8002', string $stLabel = 'Fechado'): void
    {
        DB::table('pingwin_supplier_documents')->insert(['company_id' => $this->company->id, 'docheader_id' => $id, 'docconfig_id' => $cfg, 'doctype' => $cfg,
            'document' => "DOC/{$id}", 'entity_pingwin_id' => $entity, 'entity_name' => $this->sup[$entity]->name ?? $entity, 'doc_date' => $date, 'due_date' => $due,
            'total_cents' => $total, 'total_tax_cents' => $tax, 'paid' => false, 'docstatus_id' => $st, 'docstatus_description' => $stLabel,
            'lines_status' => $lines === null ? null : 'ok', 'created_at' => now(), 'updated_at' => now()]);
        foreach ((array) $lines as $i => [$product, $cents]) {
            DB::table('pingwin_supplier_document_lines')->insert(['company_id' => $this->company->id, 'docheader_id' => $id, 'line_number' => $i + 1,
                'product_pingwin_id' => $product, 'description' => $product ? null : 'Serviço sem artigo', 'total_cents' => $cents, 'tax_value_cents' => 0,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function cc(string $id, string $cfg, float $total, float $topay, int $sig, string $date, string $due): array
    {
        $pw = static fn (string $d) => str_replace('-', '', $d) . 'T00:00:00';

        return ['docheader_id' => $id, 'docconfig_id' => $cfg, 'doctype' => $cfg, 'document' => "DOC/{$id}", 'doc_date' => $pw($date), 'due_date' => $pw($due),
            'fiscal_date' => $pw($date), 'total' => $total, 'total_paid' => round($total - $topay, 2), 'topay' => $topay, 'paid' => $topay == 0.0 ? 1 : 0,
            'ca_signal' => $sig, 'docreference_number' => '', 'docreference_date' => '', 'docheader_store_id' => 'S', 'store' => 'Loja', 'docstatus_description' => 'Fechado'];
    }

    private function month(): array
    {
        return (new MonthFinanceService($this->company->id))->month('2026-09');
    }

    private function row(array $m, string $doc): array
    {
        return collect($m['documents']['rows'])->firstWhere('docheader_id', $doc) ?? $this->fail("documento {$doc}");
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function test_family_by_the_lines_by_the_supplier_and_unclassified_with_deposits_out_of_the_result(): void
    {
        $m = $this->month();
        $groups = collect($m['result']['rows'])->where('kind', 'grupo')->pluck('cents', 'group')->all();
        // Comida: 100 − 20 (NC) + 50 (habitual, sem linhas) · Bebidas: 50 + 15 + 61,50 (em Aberto, habitual) · FSE: 200 + 1 000 (renda).
        $this->assertSame(['comida' => 13000, 'bebida' => 12650, 'pessoal' => 0, 'fse' => 120000, 'outros' => 0, 'por_classificar' => 3000], $groups);
        $this->assertSame(148650, $m['cards']['expenses']['cents']);
        $this->assertSame(3000, $m['cards']['expenses']['unclassified_cents']);

        // Cauções (pela família e pela descrição "VALOR DO DEPOSITO") fora do resultado.
        $out = collect($m['result']['out_of_result'])->pluck('cents', 'label')->all();
        $this->assertSame(['COMPRAS › DIVERSOS › Cauções de embalagens - fornecedores' => 1000, 'Depósitos de vasilhame (pela descrição do artigo)' => 500], $out);

        // De onde veio a família de cada parte.
        $this->assertSame(['linha'], array_column($this->row($m, 'D1')['parts'], 'source'));
        $d6 = $this->row($m, 'D6');
        $this->assertSame(['fornecedor', 'comida', 'COMPRAS › COMIDAS'], [$d6['parts'][0]['source'], $d6['group'], $d6['family']]);
        $d7 = $this->row($m, 'D7');
        $this->assertSame([3000, 'Linha sem artigo (ou artigo sem família) e o fornecedor não tem família habitual.'], [$d7['unclassified_cents'], $d7['unclassified_reason']]);
        $this->assertSame('D7', $m['documents']['rows'][0]['docheader_id'], '"Por classificar" no topo');
        // O acerto do cabeçalho (1 cêntimo) fica na parte maior; a NC subtrai; o anulado não conta.
        $this->assertSame(20000, $this->row($m, 'D4')['net_cents']);
        $this->assertSame([-2000, -460], [$this->row($m, 'D3')['net_cents'], $this->row($m, 'D3')['vat_cents']]);
        $this->assertFalse(collect($m['documents']['rows'])->contains('docheader_id', 'D10'));
        // Em Aberto: destacado e no cartão dos gastos.
        $this->assertTrue($this->row($m, 'D9')['open']);
        $this->assertSame(['count' => 1, 'cents' => 6150], ['count' => $m['cards']['expenses']['open_count'], 'cents' => $m['cards']['expenses']['open_cents']]);
    }

    public function test_manual_classification_fills_only_the_unclassified_by_document_or_for_the_supplier(): void
    {
        $url = "/api/v1/companies/{$this->company->id}/finance/classifications";
        $this->actingAs($this->user, 'sanctum')->postJson($url, ['scope' => 'documento', 'docheader_id' => 'D7', 'group' => 'fse'])->assertOk();
        $m = $this->month();
        $this->assertSame(['fse', 'manual_documento', 0], [$this->row($m, 'D7')['group'], $this->row($m, 'D7')['parts'][0]['source'], $this->row($m, 'D7')['unclassified_cents']]);
        $this->assertSame(123000, collect($m['result']['rows'])->firstWhere('group', 'fse')['cents']);

        // "Sempre para este fornecedor" vale para os documentos dele por classificar.
        FinanceClassification::query()->delete();
        $this->actingAs($this->user, 'sanctum')->postJson($url, ['scope' => 'fornecedor', 'docheader_id' => 'D7', 'group' => 'outros'])
            ->assertOk()->assertJsonPath('message', 'Classificado: sempre assim para Fornecedor novo.');
        $this->assertSame('manual_fornecedor', $this->row($this->month(), 'D7')['parts'][0]['source']);
        // Nunca muda uma linha que já tem família.
        $this->actingAs($this->user, 'sanctum')->postJson($url, ['scope' => 'documento', 'docheader_id' => 'D1', 'group' => 'outros'])->assertOk();
        $this->assertSame('comida', $this->row($this->month(), 'D1')['group']);
        $this->actingAs($this->user, 'sanctum')->postJson($url, ['scope' => 'documento', 'docheader_id' => 'D1', 'group' => 'xpto'])->assertStatus(422);
    }

    public function test_paid_by_settlement_estimated_paid_on_issue_open_and_overdue(): void
    {
        $m = $this->month();
        $p = fn ($id) => $this->row($m, $id)['payment'];
        $this->assertSame(['pago', '2026-09-28', false, 'Pago em 28/09'], [$p('D1')['state'], $p('D1')['paid_on'], $p('D1')['paid_estimated'], $p('D1')['label']]);
        // 71,50 € pago por duas notas (40,00 + 31,50): a data é a da que acaba de pagar, estimada.
        $this->assertSame(['pago', '2026-10-02', true, 'Pago em 02/10 (data estimada)'], [$p('D2')['state'], $p('D2')['paid_on'], $p('D2')['paid_estimated'], $p('D2')['label']]);
        $this->assertSame(['pago_no_ato', 'Pago no ato a 22/09'], [$p('D8')['state'], $p('D8')['label']]);
        $this->assertSame(['vencido', 24, 'Vencido há 24 dias'], [$p('D4')['state'], $p('D4')['days_overdue'], $p('D4')['label']]);
        $this->assertSame(['por_pagar', 100000], [$p('D5')['state'], $p('D5')['open_cents']]);
        $this->assertSame('credito', $p('D3')['state']);
        $this->assertSame('fora_cc', $p('D6')['state'], 'ainda não está na conta corrente sincronizada');

        // Dinheiro: saíram as NL de setembro (123,00 + 40,00) e a fatura-recibo (23,45); faltam pagar
        // os que vencem em setembro (246,00 + 36,90 − 24,60 de crédito); vencido: 246,00 + 36,90.
        $this->assertSame([18645, 25830, 28290], [$m['cards']['cash']['paid_cents'], $m['cards']['cash']['due_by_month_end_cents'], $m['cards']['cash']['overdue_cents']]);
        // A lista: os vencidos no topo.
        $this->assertSame(['vencido', 'vencido'], array_slice(array_column($m['cash']['payments'], 'state'), 0, 2));
        // Semanas: as saídas na semana de 14 a 20 (NL de 15/09) e de 28 a 30 (NL de 28/09).
        $weeks = collect($m['cash']['weeks'])->keyBy('from');
        $this->assertSame([4000, 12300], [$weeks['2026-09-14']['out_cents'], $weeks['2026-09-28']['out_cents']]);
        $this->assertSame(565000, $weeks['2026-09-01']['in_cents'], 'entradas: as vendas com IVA (1 e 2 de setembro)');
    }

    public function test_the_payable_total_matches_the_current_account_supplier_by_supplier(): void
    {
        $rec = $this->month()['cash']['reconciliation'];
        $this->assertTrue($rec['ok'], json_encode($rec));
        $this->assertSame([125830, 125830, []], [$rec['screen_total_cents'], $rec['cc_real_balance_cents'], $rec['mismatches']]);
        // O saldo do PingWin conta a fatura-recibo como dívida (é a diferença).
        $this->assertSame(2345, $rec['auto_paid_difference_cents']);
        $real = collect(app(SupplierCcService::class)->overview($this->company->id)['suppliers'])->pluck('real_balance_cents', 'name')->all();
        $this->assertSame(['Carnes' => -2460, 'Bebidas' => 0, 'EDP' => 24600, 'Senhorio' => 100000, 'Fornecedor novo' => 3690, 'Talho habitual' => 0], $real);
    }

    public function test_summary_sentence_cards_and_result(): void
    {
        $m = $this->month();
        $this->assertSame('Setembro de 2026: vendas de 5 000,00 €, gastos de 1 486,50 €, resultado de 3 513,50 € (70,3 %). Saíram 186,45 €; faltam pagar 258,30 € até ao fim do mês.', $m['sentence']);
        $this->assertSame('Gasto é quando a fatura chega; pago é quando o dinheiro sai.', $m['explain']);
        $this->assertSame([351350, 70.3], [$m['cards']['result']['cents'], $m['cards']['result']['pct']]);
        // Agosto: 4 000,00 € de vendas e 40,00 € de gastos.
        $this->assertSame([396000, 99.0], [$m['cards']['result']['prev_cents'], $m['cards']['result']['prev_pct']]);
        $this->assertSame(['sales_vat_cents' => 65000, 'purchases_vat_cents' => 8625, 'estimate_cents' => 56375], array_intersect_key($m['result']['vat'],
            array_flip(['sales_vat_cents', 'purchases_vat_cents', 'estimate_cents'])));
        $this->assertSame('Estimativa; a declaração é do contabilista.', $m['result']['vat']['note']);
        $this->assertSame(6, count($m['result']['chart']));
        $this->assertSame('O resultado é da empresa toda: por loja só as vendas, porque as compras entram no Economato.', $m['result']['store_note']);
        $this->assertContains('Vendas: há vendas por artigo em 2 dos 30 dias do mês.', $m['warnings']);
        // Food cost: o da Engenharia de menu (comida 100 − 20 + bebida 50 + 15 = 145 ÷ 5 000 = 2,9 %).
        $this->assertSame(2.9, $m['result']['food_cost']['pct']);
    }

    public function test_api_needs_the_finance_month_module_and_the_finance_permission(): void
    {
        $url = "/api/v1/companies/{$this->company->id}/finance/month?month=2026-09";
        $this->actingAs($this->user, 'sanctum')->getJson($url)->assertOk()
            ->assertJsonPath('data.month_label', 'setembro de 2026')
            ->assertJsonPath('data.can.classify', true)
            ->assertJsonPath('data.documents.rows.1.close_problems.0.key', 'linhas'); // D9 em Aberto, sem linhas lidas
        DB::table('company_modules')->where('company_id', $this->company->id)->where('module_key', 'financas_mes')->delete();
        $this->actingAs($this->user->fresh(), 'sanctum')->getJson($url)->assertStatus(403);
    }
}
