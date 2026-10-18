<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Audit\AuditFindingData;
use App\Audit\AuditRegistry;
use App\Audit\AuditRunService;
use App\Audit\AuditScope;
use App\Audit\Rules\Restaurant\Money\FaturaDuplicadaRule;
use App\Audit\Rules\Restaurant\Money\ForaDoPadraoRule;
use App\Audit\Rules\Restaurant\Money\GuiaSemFaturaRule;
use App\Audit\Rules\Restaurant\Money\MaisBaratoRule;
use App\Audit\Rules\Restaurant\Money\MargemPratoRule;
use App\Audit\Rules\Restaurant\Money\PrecoSubiuRule;
use App\Models\AuditFinding;
use App\Models\PermissionProfile;
use App\Models\PingwinPosDocument;
use App\Models\PingwinUnit;
use App\Models\ProductUnitConversion;
use App\Models\User;
use App\Services\Restaurant\Menu\MenuEngineeringService;
use App\Services\Restaurant\PosDocumentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Auditoria v2-C · as regras de dinheiro (C1 a C6), cada uma com um caso positivo e um negativo: a
 * subida do preço com e sem a unidade confirmada, o fornecedor mais barato, a margem do prato com o
 * ingrediente que a fez cair, as anulações com os mínimos (e só o administrador as vê), as faturas em
 * duplicado (o mesmo total noutro fornecedor não é duplicado) e a guia convertida (sem achado).
 */
class AuditMoneyRulesTest extends TestCase
{
    use BuildsStock;
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYuko();   // hoje: 15/10/2026
        PingwinUnit::create(['company_id' => $this->c, 'pingwin_id' => 'CX24', 'description' => 'Caixa 24', 'parent_pingwin_id' => '11001', 'unit_value' => 24, 'is_active' => true]);
    }

    /** @return list<AuditFindingData> */
    private function findings(string $ruleClass): array
    {
        return iterator_to_array(app($ruleClass)->run($this->c, new AuditScope($this->c, 'restauracao', 'central')), false);
    }

    /** Uma compra (fatura fechada) a um fornecedor: $qnt na unidade $unit, o total em cêntimos. */
    private function buy(string $pid, string $date, float $qnt, int $totalCents, string $supplier = 'Castro Linhares', string $nif = '501000001',
        string $unit = '11002', string $unitDesc = 'Quilograma', float $unitValue = 1, array $doc = []): string
    {
        $id = 'H' . (++$this->seq);
        DB::table('pingwin_supplier_documents')->insert(array_merge([
            'company_id' => $this->c, 'docheader_id' => $id, 'docconfig_id' => '1209', 'doctype' => 'Fatura de fornecedor', 'document' => "VFT BOVFT/{$this->seq}",
            'doc_date' => $date, 'docstatus_id' => '8002', 'docstatus_description' => 'Fechado', 'entity_name' => $supplier, 'entity_pingwin_id' => "E{$nif}",
            'tax_number' => $nif, 'total_cents' => $totalCents, 'created_at' => now(), 'updated_at' => now(),
        ], $doc));
        DB::table('pingwin_supplier_document_lines')->insert([
            'company_id' => $this->c, 'docheader_id' => $id, 'line_number' => 1, 'product_pingwin_id' => $pid, 'description' => "Linha {$pid}",
            'qnt' => $qnt, 'unit_desc' => $unitDesc, 'price' => $totalCents / 100 / $qnt, 'total_cents' => $totalCents,
            'raw' => json_encode(['qnt' => $qnt, 'qnt_base' => $qnt * $unitValue, 'unit_id' => $unit, 'unit_value' => $unitValue]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function product(string $pid, string $description, array $extra = []): void
    {
        $this->catalog($this->c, $pid, array_merge(['description' => $description, 'family' => 'Família \\ Compras \\ Carne', 'forsale' => false, 'forpurchase' => true,
            'stockconfig_id' => '7501', 'base_unit_id' => '11002'], $extra));
    }

    // ── C1 · O preço do fornecedor subiu ──────────────────────────────────

    public function test_c1_price_rise_on_the_confirmed_unit_counts_the_rise_times_the_quantity_since(): void
    {
        $this->product('BIFE', 'Bife novilho');
        $this->buy('BIFE', '2026-08-01', 10, 10000);
        $this->buy('BIFE', '2026-09-01', 10, 10000);
        $this->buy('BIFE', '2026-10-01', 10, 11800);   // 11,80 €/kg: +18 % na Castro Linhares
        $this->product('ARROZ', 'Arroz');
        foreach (['2026-08-01' => 1000, '2026-09-01' => 1000, '2026-10-01' => 1050] as $d => $t) {
            $this->buy('ARROZ', $d, 10, $t);   // +5 %: abaixo dos 8 %
        }
        $this->product('LUZ', 'Eletricidade', ['stockconfig_id' => '7500', 'base_unit_id' => '11001']);
        foreach (['2026-08-01' => 10000, '2026-09-01' => 10000, '2026-10-01' => 20000] as $d => $t) {
            $this->buy('LUZ', $d, 1, $t, 'EDP', '503504564', '11001', 'Unidade');   // uma despesa, não um produto
        }

        $f = $this->findings(PrecoSubiuRule::class);
        $this->assertSame(['BIFE'], array_map(fn ($x) => $x->entityId, $f));
        $this->assertSame('Bife novilho: subiu 18% na Castro Linhares', $f[0]->title);
        $this->assertSame(1800, $f[0]->impactCents);   // (11,80 − 10,00) × 10 kg
        $this->assertNull($f[0]->impactPendingCents);
        $this->assertSame('A 01/10/2026, Castro Linhares faturou Bife novilho a 11,80 € por quilograma. Nos 90 dias antes, a mediana era 10,00 €: subiu 18%.', $f[0]->seen);
        $this->assertStringContainsString('por mês', $f[0]->stake);
        $this->assertSame('historico_precos', $f[0]->fixAction['type']);

        // Configurável por família: com 20 % na carne, já não é achado.
        config(['auditoria.money.price_rise_pct_by_family' => ['Carne' => 20]]);
        $this->assertSame([], $this->findings(PrecoSubiuRule::class));
    }

    public function test_c1_without_a_confirmed_purchase_unit_the_euros_are_to_confirm(): void
    {
        $this->product('COLA', 'Coca Cola lata', ['base_unit_id' => '11001']);
        $this->buy('COLA', '2026-08-01', 1, 1200, 'Sumol Compal', '502000002', 'CX24', 'Caixa 24', 24);
        $this->buy('COLA', '2026-09-01', 1, 1200, 'Sumol Compal', '502000002', 'CX24', 'Caixa 24', 24);
        $this->buy('COLA', '2026-10-01', 2, 2880, 'Sumol Compal', '502000002', 'CX24', 'Caixa 24', 24);   // 14,40 € a caixa: +20 %

        $f = $this->findings(PrecoSubiuRule::class);
        $this->assertCount(1, $f);
        $this->assertNull($f[0]->impactCents);
        $this->assertSame(480, $f[0]->impactPendingCents);   // (0,60 − 0,50) × 48 latas, a confirmar
        $this->assertStringStartsWith('A confirmar, ', $f[0]->stake);

        ProductUnitConversion::create(['company_id' => $this->c, 'product_pingwin_id' => 'COLA', 'unit_key' => 'CX24', 'unit_label' => 'Caixa 24',
            'supplier_nif' => '', 'factor_to_base' => 24, 'source' => 'confirmado']);
        $f = $this->findings(PrecoSubiuRule::class);
        $this->assertSame([480, null], [$f[0]->impactCents, $f[0]->impactPendingCents]);
    }

    public function test_c1_cheaper_at_another_supplier_shows_the_supplier_date_and_document(): void
    {
        $this->product('QUEIJO', 'Queijo barra');
        $this->buy('QUEIJO', '2026-08-28', 300, 123000, 'Pinheiro', '503000003');    // 4,10 €/kg (BOVFT/1)
        $this->buy('QUEIJO', '2026-09-10', 30, 13620, 'Osfama', '504000004');        // 4,54 €/kg
        $this->buy('QUEIJO', '2026-09-20', 30, 13620, 'Osfama', '504000004');
        $this->product('OVOS', 'Ovos');
        $this->buy('OVOS', '2026-09-01', 30, 3000, 'Pinheiro', '503000003');         // 1,00
        $this->buy('OVOS', '2026-10-01', 30, 2700, 'Osfama', '504000004');           // 0,90: a última já é a mais barata

        $f = $this->findings(MaisBaratoRule::class);
        // Só a Osfama (a mais cara) é achado; a Pinheiro já é a mais barata.
        $this->assertSame([['QUEIJO', '504000004']], array_map(fn ($x) => [$x->entityId, $x->evidenceKey], $f));
        $this->assertSame('A última compra de Queijo barra à Osfama foi a 4,54 € por quilograma. A 28/08/2026, a Pinheiro faturou a 4,10 € por quilograma (BOVFT/1).', $f[0]->seen);
        // (4,54 − 4,10) × os 60 kg comprados à Osfama em 90 dias / (90 / 30,4) por mês, não os 360 kg todos
        $this->assertEqualsWithDelta((int) round(0.44 * 60 / (90 / 30.4) * 100), $f[0]->impactCents, 1);
        config(['auditoria.money.cheaper_min_eur' => 10]);   // abaixo do mínimo por mês: nada
        $this->assertSame([], $this->findings(MaisBaratoRule::class));
        $this->assertSame('comparar_fornecedores', $f[0]->fixAction['type']);
    }

    // ── C2 · A margem do prato caiu ───────────────────────────────────────

    public function test_c2_dish_margin_fell_names_the_ingredient_and_the_supplier(): void
    {
        $this->product('BIFE', 'Bife');
        $this->buy('BIFE', '2026-09-01', 10, 12000);                 // 12,00 €/kg
        $this->buy('BIFE', '2026-10-01', 10, 16000);                 // 16,00 €/kg: média 14, último 16
        $this->catalog($this->c, 'FRAN', ['description' => 'Francesinha', 'family' => 'Família \\ Comidas \\ Pratos', 'forsale' => true, 'has_bom' => true]);
        $this->persistBom($this->c, $this->bom('FRAN', [['00000000', 'BFRAN', '2026-05-01', ['S1' => true],
            [$this->bomLine('BIFE', 0.2, ['unit_id' => '11002', 'unit_desc' => 'Quilograma', 'component_description' => 'Bife'])]]]));
        $this->catalog($this->c, 'SOPA', ['description' => 'Sopa', 'family' => 'Família \\ Comidas \\ Pratos', 'forsale' => true, 'has_bom' => true]);
        $this->persistBom($this->c, $this->bom('SOPA', [['00000000', 'BSOPA', '2026-05-01', ['S1' => true], [$this->bomLine('SEMCOMPRAS', 1)]]]));
        $this->sale($this->c, $this->locBaixa, 'FRAN', '2026-10-05', 90, 90000, 'Família \\ Comidas \\ Pratos');   // 10,00 € sem IVA
        $this->sale($this->c, $this->locBaixa, 'SOPA', '2026-10-05', 90, 27000, 'Família \\ Comidas \\ Pratos');
        foreach (['media', 'ultimo'] as $mode) {
            (new MenuEngineeringService($this->c))->snapshot('2026-07-17', '2026-10-14', null, $mode);
        }

        $f = $this->findings(MargemPratoRule::class);
        $this->assertSame(['FRAN'], array_map(fn ($x) => $x->entityId, $f));   // a sopa não tem custo: não entra
        $this->assertSame('Francesinha: food cost de 28% para 32%', $f[0]->title);
        $this->assertStringStartsWith('Francesinha passou de 28% para 32% de food cost: bife subiu 14% na Castro Linhares.', $f[0]->seen);
        // +0,40 € por prato × 90 vendidos em 90 dias (30,4 por mês)
        $this->assertEqualsWithDelta((int) round(0.4 * 90 / 90 * 30.4 * 100), $f[0]->impactCents, 1);
        $this->assertSame('simular_preco', $f[0]->fixAction['type']);
        $this->assertSame([0.25, 0.5, 1.0], $f[0]->fixAction['steps']);
        $this->assertStringContainsString('prato=FRAN', (string) $f[0]->fixUrl);

        // Abaixo dos limiares (3 pp e 10 %) não é achado.
        config(['auditoria.money.margin_food_cost_pp' => 5, 'auditoria.money.margin_cost_pct' => 20]);
        $this->assertSame([], $this->findings(MargemPratoRule::class));
    }

    // ── C3 · Anulações fora do padrão ─────────────────────────────────────

    private function pos(string $type, string $employee, string $date, string $time, int $cents, string $store = 'S1'): void
    {
        $id = 'P' . (++$this->seq);
        PosDocumentsService::upsert($this->c, [[
            'id' => $id, 'docconfig_id' => $type, 'doctype' => $type, 'document' => "DOC {$id}", 'store_id' => $store, 'store' => 'Loja Baixa',
            'doc_date' => str_replace('-', '', $date) . 'T00:00:00', 'doc_time' => "00000000T{$time}", 'total' => $cents / 100,
            'employee_id' => "E-{$employee}", 'employee' => $employee, 'docstatus_id' => '8002',
        ]]);
    }

    /** A semana de 05/10 a 11/10: $sales vendas de 50 € e $voids anulações de $voidCents, por funcionário. */
    private function week(array $people): void
    {
        foreach ($people as $name => [$sales, $voids, $voidCents]) {
            for ($i = 0; $i < $sales; $i++) {
                $this->pos('1018', $name, '2026-10-0' . (5 + $i % 5), sprintf('%02d:%02d:00', 12 + $i % 8, $i % 60), 5000);
            }
            for ($i = 0; $i < $voids; $i++) {
                $this->pos('1002', $name, '2026-10-06', sprintf('20:%02d:00', 10 + $i), $voidCents);
            }
        }
    }

    public function test_c3_voids_above_twice_the_colleagues_median_with_the_minimums(): void
    {
        $this->week(['Ana' => [12, 4, 5000], 'Bruno' => [15, 1, 1000], 'Carla' => [12, 1, 600], 'Duarte' => [9, 6, 5000]]);
        $this->pos('1031', 'Ana', '2026-10-06', '19:50:00', 5000);   // a consulta de mesa com o mesmo total, antes da anulação
        // O espelho só chega a 09/10: a semana de 05/10 a 11/10 ainda não foi lida por inteiro.
        $this->assertSame([], $this->findings(ForaDoPadraoRule::class));
        $this->pos('1018', 'Outro', '2026-10-13', '13:00:00', 5000);   // a leitura já passou o domingo

        $f = $this->findings(ForaDoPadraoRule::class);
        // Duarte tem menos de 10 vendas na semana: não entra (nem como colega).
        $this->assertSame(['S1|E-Ana'], array_map(fn ($x) => $x->entityId, $f));
        $this->assertSame('Padrão fora do habitual a rever', app(ForaDoPadraoRule::class)->title());
        $this->assertStringContainsString('anulações fora do habitual, semana de 05/10 a 11/10', $f[0]->title);
        // 200 € anulados − a mediana dos colegas (1,33 % e 1,00 % → 1,17 %) × 600 € de vendas
        $median = (1000 / 75000 + 600 / 60000) / 2;
        $this->assertSame((int) round(20000 - $median * 60000), $f[0]->impactCents);
        $this->assertStringContainsString('depois da consulta de mesa com o mesmo total', $f[0]->seen);
        $this->assertTrue(app(ForaDoPadraoRule::class)->sensitive());
    }

    public function test_c3_nothing_when_the_voids_are_like_the_colleagues(): void
    {
        $this->week(['Ana' => [12, 1, 900], 'Bruno' => [15, 1, 1000], 'Carla' => [12, 1, 800]]);
        $this->pos('1018', 'Outro', '2026-10-13', '13:00:00', 5000);
        $this->assertSame([], $this->findings(ForaDoPadraoRule::class));
    }

    public function test_c3_only_the_administrator_and_the_owner_see_it(): void
    {
        config(['auditoria.rules' => [ForaDoPadraoRule::class, FaturaDuplicadaRule::class]]);
        $this->week(['Ana' => [12, 4, 5000], 'Bruno' => [15, 1, 1000], 'Carla' => [12, 1, 600]]);
        $this->pos('1018', 'Outro', '2026-10-13', '13:00:00', 5000);
        $this->buy('X', '2026-10-01', 1, 9900, 'Recheio', '505000005', '11001', 'Unidade');
        $this->buy('Y', '2026-10-02', 1, 9900, 'Recheio', '505000005', '11001', 'Unidade');
        app(AuditRunService::class)->start($this->c, 'restauracao', 'central');
        $this->assertSame(2, AuditFinding::where('company_id', $this->c)->count());

        $admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin']);
        $p = PermissionProfile::create(['company_id' => $this->c, 'side' => 'cliente', 'name' => 'Sala', 'is_system' => false, 'is_suggestion' => false]);
        $p->syncPermissions(['restauracao.ver', 'restauracao.editar', 'financas.ver']);
        $manager = User::factory()->create(['company_id' => $this->c, 'role' => 'user', 'profile_id' => $p->id]);
        $url = "/api/v1/companies/{$this->c}/audit";

        $this->assertSame(2, $this->actingAs($admin, 'sanctum')->getJson("{$url}/findings")->assertOk()->json('data.summary.total'));
        $r = $this->actingAs($manager, 'sanctum')->getJson("{$url}/findings")->assertOk();
        $this->assertSame(1, $r->json('data.summary.total'));
        $this->assertSame('restauracao.dinheiro_fatura_duplicada', $r->json('data.findings.data.0.rule'));
        $hidden = AuditFinding::where('rule', 'restauracao.dinheiro_fora_do_padrao')->firstOrFail();
        $this->actingAs($manager, 'sanctum')->getJson("{$url}/findings/{$hidden->id}")->assertNotFound();
        $this->actingAs($manager, 'sanctum')->postJson("{$url}/restauracao/findings/{$hidden->id}/state", ['action' => 'aceitar'])->assertNotFound();
        $rules = collect($this->actingAs($manager, 'sanctum')->getJson("{$url}/meta")->json('data.areas.0.rules'))->pluck('code')->all();
        $this->assertNotContains('restauracao.dinheiro_fora_do_padrao', $rules);
        $this->assertSame(['restauracao.dinheiro_fatura_duplicada'], array_column($this->actingAs($manager, 'sanctum')->getJson("{$url}/findings/groups")->json('data.groups'), 'rule'));
        // O grupo "Dinheiro".
        $this->assertSame(2, $this->actingAs($admin, 'sanctum')->getJson("{$url}/findings?family=dinheiro")->json('data.summary.total'));
        $this->assertSame(0, $this->actingAs($admin, 'sanctum')->getJson("{$url}/findings?family=unidades")->json('data.summary.total'));
        // A Bússola, "Onde está a fugir dinheiro": o top 3 pelo € em jogo, nunca a regra com nomes de funcionários.
        $block = (new \ReflectionMethod(\App\Services\Restaurant\RestaurantCompassService::class, 'moneyBlock'))->invoke(app(\App\Services\Restaurant\RestaurantCompassService::class), $this->c);
        $this->assertSame(['Faturas de fornecedor em duplicado'], array_column($block['items'], 'rule_title'));
        $this->assertSame(9900, $block['open_cents']);
        $this->assertSame('Onde está a fugir dinheiro: 99 € em jogo nos achados de dinheiro por resolver.', $block['headline']);
    }

    // ── C5 · Faturas em duplicado ─────────────────────────────────────────

    public function test_c5_same_supplier_same_total_within_15_days_is_a_duplicate_but_not_at_another_supplier(): void
    {
        $a = $this->buy('X', '2026-10-01', 1, 62977, 'Recheio', '505000005', '11001', 'Unidade');
        $b = $this->buy('Y', '2026-10-01', 1, 62977, 'Recheio', '505000005', '11001', 'Unidade');
        $this->buy('Z', '2026-10-02', 1, 41000, 'Makro', '506000006', '11001', 'Unidade');
        $this->buy('Z', '2026-10-03', 1, 41000, 'Pingo Doce', '507000007', '11001', 'Unidade');   // o mesmo total noutro fornecedor: não

        $f = $this->findings(FaturaDuplicadaRule::class);
        $this->assertSame([$b], array_map(fn ($x) => $x->entityId, $f));
        $this->assertSame(62977, $f[0]->impactCents);
        $this->assertSame(['type' => 'documentos_lado_a_lado', 'documents' => [$a, $b]], $f[0]->fixAction);
        $this->assertStringContainsString('com o mesmo total (629,77 €) no mesmo dia', $f[0]->seen);
    }

    public function test_c5_same_reference_twice_lines_recurring_totals_and_different_references(): void
    {
        $this->buy('X', '2026-07-01', 1, 1000, 'Uber Eats', '508000008', '11001', 'Unidade', 1, ['docreference_number' => 'FT 2026/77']);
        $ref = $this->buy('X', '2026-09-01', 1, 1200, 'Uber Eats', '508000008', '11001', 'Unidade', 1, ['docreference_number' => 'FT 2026/77']);
        foreach (['2026-08-03', '2026-08-10', '2026-08-17'] as $d) {
            $this->buy('W', $d, 1, 63955, 'Factorial', '509000009', '11001', 'Unidade');   // uma avença semanal: não é duplicado
        }
        $this->buy('V', '2026-10-01', 1, 5000, 'Makro', '506000006', '11001', 'Unidade', 1, ['docreference_number' => 'FA 1']);
        $this->buy('V', '2026-10-02', 1, 5000, 'Makro', '506000006', '11001', 'Unidade', 1, ['docreference_number' => 'FA 2']);   // duas faturas do fornecedor

        $f = $this->findings(FaturaDuplicadaRule::class);
        $this->assertSame([$ref], array_map(fn ($x) => $x->entityId, $f));
        $this->assertStringContainsString('a mesma referência do fornecedor (FT 2026/77)', $f[0]->seen);
    }

    // ── C6 · Guias sem fatura ─────────────────────────────────────────────

    public function test_c6_guide_older_than_30_days_without_invoice_and_a_converted_guide(): void
    {
        $guide = fn (string $id, string $doc, string $date) => DB::table('pingwin_supplier_documents')->insert([
            'company_id' => $this->c, 'docheader_id' => $id, 'docconfig_id' => '1203', 'doctype' => 'Guia de remessa', 'document' => $doc, 'doc_date' => $date,
            'docstatus_id' => '8002', 'docstatus_description' => 'Fechado', 'entity_name' => 'Castro Linhares', 'entity_pingwin_id' => 'CL', 'total_cents' => 25000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $guide('G1', 'VGR GR/1', '2026-08-20');    // 56 dias, sem fatura: achado
        $guide('G2', 'VGR GR/2', '2026-08-25');    // convertida (uma linha da fatura aponta para ela)
        $guide('G3', 'VGR GR/3', '2026-08-26');    // a fatura tem o número dela na referência
        $guide('G4', 'VGR GR/4', '2026-10-01');    // só 14 dias
        $inv = $this->buy('BIFE', '2026-09-01', 10, 25000, 'Castro Linhares', '501000001');
        DB::table('pingwin_supplier_document_lines')->where('docheader_id', $inv)->update(['raw' => json_encode(['qnt' => 10, 'qnt_base' => 10, 'unit_id' => '11002', 'src_docheader_id' => 'G2'])]);
        $this->buy('BIFE', '2026-09-02', 10, 25000, 'Castro Linhares', '501000001', '11002', 'Quilograma', 1, ['docreference_number' => 'GR/3']);

        $f = $this->findings(GuiaSemFaturaRule::class);
        $this->assertSame(['G1'], array_map(fn ($x) => $x->entityId, $f));
        $this->assertSame(25000, $f[0]->impactCents);
        $this->assertSame('GR/1: guia sem fatura há 56 dias', $f[0]->title);
    }

    // ── O registo e a sincronização ───────────────────────────────────────

    public function test_money_family_in_the_registry_and_the_pos_documents_mirror(): void
    {
        $this->assertSame(['restauracao.dinheiro_preco_subiu', 'restauracao.dinheiro_mais_barato', 'restauracao.dinheiro_margem_prato', 'restauracao.dinheiro_fora_do_padrao',
            'restauracao.dinheiro_fatura_duplicada', 'restauracao.dinheiro_guia_sem_fatura'], AuditRegistry::familyCodes('dinheiro'));
        $this->assertSame(['restauracao.dinheiro_fora_do_padrao'], AuditRegistry::sensitiveCodes());

        PosDocumentsService::upsert($this->c, [['id' => '9', 'docconfig_id' => '1002', 'doc_date' => '20261017T00:00:00', 'doc_time' => '00000000T23:44:05',
            'total' => 30.4, 'employee_id' => '77', 'employee' => 'Andre', 'store_id' => 'S1', 'store' => 'Loja', 'accumulator' => 17]]);
        PosDocumentsService::upsert($this->c, [['id' => '9', 'docconfig_id' => '1002', 'doc_date' => '20261017T00:00:00', 'doc_time' => '00000000T23:44:05', 'total' => 30.4]]);
        $d = PingwinPosDocument::where('company_id', $this->c)->sole();
        $this->assertSame(['2026-10-17', '23:44:05', 3040], [substr((string) $d->doc_date, 0, 10), substr((string) $d->doc_time, 0, 8), (int) $d->total_cents]);
    }
}
