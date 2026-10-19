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
use Tests\Feature\Concerns\FinanceFixtures;
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

    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFinance();
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
