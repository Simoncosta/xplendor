<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\StockThresholds;
use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarMarketAggregate;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\MetaAd;
use App\Models\MetaAdInsightDaily;
use App\Models\User;
use App\Repositories\DashboardRepository;
use App\Services\Automotive\AutomotiveHubService;
use App\Services\CarIssueEngine;
use App\Services\MetaAdCarAllocator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — FASE 2C (Parte A): Hub do Automóvel.
 *
 * Cobre: resumo pelas fontes únicas, salvaguarda do fallback (comparação aproximada
 * nunca conta como acima do mercado), gasto Meta separado (viatura / stock geral /
 * por atribuir), migração das 4 regras do CarIssueEngine (mesmos resultados quando
 * a janela não muda nada; diferenças só pela janela e pela salvaguarda), funil por
 * viatura (contactos do view alargado, CPL sem divisão por zero, vendas da janela),
 * avisos de qualidade e tenancy.
 */
class AutomotiveHubTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-03 10:00:00';

    private Company $company;
    private Company $other;
    private User $user;
    private User $otherUser;
    private int $brandId;
    private int $modelId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500008001', 'fiscal_name' => 'Stand A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500008002', 'fiscal_name' => 'Stand B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->otherUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);

        $brand = CarBrand::firstOrCreate(['slug' => 'vw'], ['name' => 'VW', 'vehicle_type' => 'car']);
        $this->brandId = $brand->id;
        $this->modelId = CarModel::firstOrCreate(['name' => 'Golf', 'car_brand_id' => $brand->id])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function car(int $daysOld, array $attrs = [], ?Company $company = null): Car
    {
        $car = Car::factory()->create(array_merge([
            'company_id' => ($company ?? $this->company)->id, 'car_brand_id' => $this->brandId, 'car_model_id' => $this->modelId,
            'vehicle_type' => 'car', 'status' => 'active', 'is_resume' => 0, 'price_gross' => 20000, 'promo_price_gross' => null,
            'description_website_pt' => str_repeat('Descrição completa. ', 12),
        ], array_diff_key($attrs, ['sold_at' => 1])));

        $update = ['created_at' => now()->subDays($daysOld), 'car_created_at' => null];
        if (array_key_exists('sold_at', $attrs)) {
            $update['sold_at'] = $attrs['sold_at'];
        }
        DB::table('cars')->where('id', $car->id)->update($update);

        return $car->fresh();
    }

    private function aggregate(Car $car, float $median, string $confidence = 'high', bool $fallback = false): void
    {
        CarMarketAggregate::create([
            'car_id' => $car->id, 'vehicle_type' => $car->vehicle_type, 'status' => 'success', 'confidence' => $confidence,
            'comparables_count' => 12, 'median_price' => $median, 'fallback_used' => $fallback,
        ]);
    }

    private function views(Car $car, int $n, int $daysAgo = 1): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['company_id' => $car->company_id, 'car_id' => $car->id, 'ip_address' => '127.0.0.1',
                'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('car_views')->insert($chunk);
        }
    }

    private function interaction(Car $car, string $type, int $daysAgo = 1, int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('car_interactions')->insert(['company_id' => $car->company_id, 'car_id' => $car->id, 'interaction_type' => $type,
                'visitor_id' => "v{$i}", 'session_id' => "s{$i}", 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
        }
    }

    private function lead(Car $car, int $daysAgo = 1, string $channel = 'direct'): void
    {
        DB::table('car_leads')->insert(['name' => 'Cliente', 'email' => 'c@x.pt', 'car_id' => $car->id, 'company_id' => $car->company_id,
            'status' => 'new', 'channel' => $channel, 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
    }

    private function images(Car $car, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('car_images')->insert(['car_id' => $car->id, 'company_id' => $car->company_id, 'image' => "/i/{$car->id}-{$i}.jpg",
                'order' => $i, 'is_primary' => $i === 0, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Anúncio Meta + gasto de um dia (ingestão por anúncio simulada) e atribuição. */
    private function adSpend(string $adId, ?string $name, float $spend, int $daysAgo = 2): void
    {
        MetaAdInsightDaily::create(['company_id' => $this->company->id, 'account_id' => '123', 'date' => now()->subDays($daysAgo)->toDateString(),
            'campaign_id' => 'C1', 'adset_id' => 'S1', 'ad_id' => $adId, 'ad_name' => $name, 'spend' => $spend, 'impressions' => 100, 'clicks' => 3]);
        MetaAd::updateOrCreate(['company_id' => $this->company->id, 'account_id' => '123', 'ad_id' => $adId],
            ['ad_name' => $name, 'campaign_id' => 'C1', 'effective_status' => 'ACTIVE']);
        app(MetaAdCarAllocator::class)->rebuild($this->company->id, '123');
    }

    private function hub(): AutomotiveHubService
    {
        return app(AutomotiveHubService::class);
    }

    // ── 1. RESUMO ─────────────────────────────────────────────────────────────

    public function test_summary_reads_the_single_sources(): void
    {
        $this->car(10);
        $this->car(50);                                        // ≥ 45 (carro) → parada
        $this->car(130, ['vehicle_type' => 'motorhome']);      // ≥ 120 (autocaravana) → parada
        $this->car(100, ['vehicle_type' => 'motorhome']);      // < 120 → não
        $this->car(300, ['is_resume' => 1]);                   // retoma: fora do capital
        $this->car(400, ['status' => 'sold']);                 // vendida: fora do stock

        $s = $this->hub()->summary($this->company->id);
        $dash = app(DashboardRepository::class);

        $this->assertSame($dash->getSummary($this->company->id)['total_cars'], $s['stock']['total_cars']);
        $this->assertSame($dash->getSummary($this->company->id)['avg_days_in_stock'], $s['stock']['avg_days_in_stock']);
        $this->assertSame(round($dash->getCapitalSummary($this->company->id)['stuck_capital_over_threshold'], 2), $s['stuck_capital']['amount']);
        $this->assertSame(2, $s['stuck_capital']['cars']);
        $this->assertSame(40000.0, $s['stuck_capital']['amount']);
        $this->assertSame(StockThresholds::ageThresholdFor('motorhome'), $s['stuck_capital']['thresholds']['motorhome']);
    }

    public function test_above_market_only_counts_exact_comparisons_with_medium_or_high_confidence(): void
    {
        $exactAbove = $this->car(10, ['price_gross' => 23000]);
        $this->aggregate($exactAbove, 20000, 'high');                 // +15%, exata → conta
        // Decisão 1: aproximado só quando a comparação pode ter largado o modelo
        // (aggregate antigo de autocaravana, da cascata). Um carro com recurso
        // mantém marca e modelo: conta como exato, com "critérios alargados".
        $approxAbove = $this->car(10, ['vehicle_type' => 'motorhome', 'price_gross' => 24000]);
        $this->aggregate($approxAbove, 20000, 'high', true);          // +20%, cascata antiga → aproximada
        $widened = $this->car(10, ['price_gross' => 22500]);
        $this->aggregate($widened, 20000, 'high', true);              // +12,5%, carro com recurso → exata
        $lowConf = $this->car(10, ['price_gross' => 26000]);
        $this->aggregate($lowConf, 20000, 'low');                     // confiança baixa
        $fair = $this->car(10, ['price_gross' => 20000]);
        $this->aggregate($fair, 20000, 'medium');                     // alinhada, exata
        $this->car(10);                                               // sem dados

        $p = $this->hub()->summary($this->company->id)['price_position'];

        $this->assertSame(2, $p['above_market_cars']);
        $this->assertSame(3, $p['eligible_cars']);
        $this->assertSame(66.7, $p['above_market_pct']);
        $this->assertSame(1, $p['approximate_cars']);
        $this->assertSame(1, $p['low_confidence_cars']);
        $this->assertSame(1, $p['no_data_cars']);

        $byCar = collect($p['positions'])->keyBy('car_id');
        $this->assertSame('exact', $byCar[$exactAbove->id]['comparison']);
        $this->assertSame('above_market', $byCar[$exactAbove->id]['display_position']);
        $this->assertSame('approximate', $byCar[$approxAbove->id]['comparison']);
        $this->assertSame('approximate_comparison', $byCar[$approxAbove->id]['display_position']);
        $this->assertFalse($byCar[$approxAbove->id]['counts_as_above_market']);
        $this->assertSame('exact', $byCar[$widened->id]['comparison']);
        $this->assertTrue($byCar[$widened->id]['criteria_widened']);
        $this->assertTrue($byCar[$widened->id]['counts_as_above_market']);
        $this->assertFalse($byCar[$exactAbove->id]['criteria_widened']);
        $this->assertNull($byCar[$this->company->cars()->latest('id')->first()->id]['comparison'] ?? null);
    }

    public function test_meta_spend_is_split_by_car_general_stock_and_unattributed(): void
    {
        $car = $this->car(10);
        $this->adSpend('A1', "Golf [id:{$car->id}]", 40.0);       // por viatura
        $this->adSpend('A2', 'Campanha geral de stock', 25.0);     // stock geral (sem tag)
        $this->adSpend('A3', 'Golf [id:999999]', 5.0);             // tag inválida
        $this->adSpend('A4', "Antigo [id:{$car->id}]", 99.0, 45);  // fora dos 30 dias

        $m = $this->hub()->summary($this->company->id)['meta_spend'];

        $this->assertTrue($m['ad_level_available']);
        $this->assertTrue($m['uses_tags']);
        $this->assertSame(40.0, $m['by_car']);
        $this->assertSame(40.0, $m['by_car_tag']);
        $this->assertSame(25.0, $m['general_stock']);
        $this->assertSame(5.0, $m['unattributed']);
        $this->assertSame('2026-09-04', $m['from']);
    }

    public function test_meta_spend_without_ad_level_ingestion_says_so(): void
    {
        $m = $this->hub()->summary($this->company->id)['meta_spend'];

        $this->assertFalse($m['ad_level_available']);
        $this->assertNull($m['general_stock']);
        $this->assertSame(0.0, $m['by_car']);
    }

    // ── 2. RECOMENDAÇÕES: migração das 4 regras ─────────────────────────────

    /** Stock em que toda a atividade cabe nos últimos 30 dias e nada é aproximado. */
    private function equivalenceFixture(): array
    {
        // Dias fora da faixa 45–60 (ver test_dead_stock_uses_the_threshold_of_the_vehicle_type).
        $a = $this->car(100, ['price_gross' => 23000]);   // parada + acima do mercado
        $this->aggregate($a, 20000);
        $this->views($a, 10);
        $this->images($a, 8);

        $b = $this->car(20);                               // pouca procura
        $this->views($b, 5);
        $this->images($b, 8);

        $c = $this->car(10, ['description_website_pt' => 'Curta.']);   // anúncio fraco
        $this->views($c, 60);
        $this->images($c, 2);

        $d = $this->car(30, ['price_gross' => 20000]);     // saudável
        $this->aggregate($d, 20000);
        $this->views($d, 120);
        $this->interaction($d, 'whatsapp_click', 2, 6);
        $this->images($d, 10);

        $e = $this->car(200, ['price_gross' => 21800]);    // +9%, com leads → preço
        $this->aggregate($e, 20000, 'medium');
        $this->views($e, 90);
        $this->lead($e, 3);
        $this->lead($e, 4);
        $this->images($e, 8);

        $f = $this->car(150, ['price_gross' => 20000]);   // parada, preço alinhado, 1 lead
        $this->aggregate($f, 20000);
        $this->views($f, 100);
        $this->interaction($f, 'whatsapp_click', 2, 6);
        $this->lead($f, 6);
        $this->images($f, 8);

        return [$a, $b, $c, $d, $e, $f];
    }

    public function test_migrated_rules_match_the_old_engine_when_the_window_changes_nothing(): void
    {
        $this->equivalenceFixture();

        // A escala de prioridade mudou de propósito (decisão 5: o topo comprimido, 100
        // raro); o que tem de bater é o PROBLEMA PRINCIPAL escolhido para cada viatura.
        $old = collect(app(CarIssueEngine::class)->getImmediateActions($this->company->id, 10))
            ->map(fn ($r) => [$r['id'], $r['issue_type']])
            ->sortBy(fn ($r) => $r[0])->values()->all();

        // Só as regras migradas (as novas do 2D desligadas para esta comparação).
        foreach (['automotive_sold_car_ad_active', 'automotive_high_views_no_contacts', 'automotive_spend_without_lead'] as $key) {
            DB::table('company_recommendation_settings')->insert(['company_id' => $this->company->id, 'rule_key' => $key,
                'enabled' => false, 'params' => null, 'created_at' => now(), 'updated_at' => now()]);
        }

        $recs = $this->hub()->recommendations($this->company, null, 10)['recommendations'];
        $new = collect($recs)
            ->map(fn ($r) => [$r['evidence']['car_id'], $r['evidence']['issue_type']])
            ->sortBy(fn ($r) => $r[0])->values()->all();
        $this->assertLessThan(100, max(array_column($recs, 'priority')));   // 100 fica reservado

        // 2D: "acima do mercado e parada" sai fundida (um só tipo).
        foreach ($new as $i => $row) {
            if ($row[1] === 'price_above_market_stale') {
                $this->assertContains($old[$i][1], ['price_above_market', 'dead_stock']);
                $new[$i][1] = $old[$i][1];
            }
        }

        $this->assertSame(['dead_stock', 'low_demand', 'poor_listing', 'price_above_market'], collect($old)->pluck(1)->unique()->sort()->values()->all());
        $this->assertSame($old, $new);
    }

    public function test_recommendation_payload_has_level_title_why_with_numbers_and_action(): void
    {
        [$a] = $this->equivalenceFixture();

        $recs = collect($this->hub()->recommendations($this->company)['recommendations']);
        $top = $recs->firstWhere('evidence.car_id', $a->id);

        $this->assertLessThanOrEqual(5, $recs->count());
        // Acima do mercado (100) e parada (99): uma só recomendação fundida.
        $this->assertSame('high', $top['level']);
        $this->assertSame(88, $top['priority']);   // bruto 100 → 70 + 30 × 0,6
        $this->assertSame('Acima do mercado e parada', $top['title']);
        $this->assertSame('price_above_market_stale', $top['evidence']['issue_type']);
        $this->assertStringContainsString('15,0% acima da mediana de 12 anúncios comparáveis', $top['why']);
        $this->assertStringContainsString('em stock há 100 dias, acima do limiar de 45 dias', $top['why']);
        $this->assertStringNotContainsString('—', $top['why']);
        $this->assertSame('Rever preço', $top['action']['label']);
        $this->assertSame("/cars/{$a->id}/intelligence", $top['action']['url']);
        $this->assertSame('exact', $top['evidence']['comparison']);
        $this->assertSame(1, $recs->where('evidence.car_id', $a->id)->count());   // uma por viatura
        $this->assertSame(30, $top['evidence']['signals']['window_days']);
    }

    public function test_signals_use_the_recent_window_not_all_time_totals(): void
    {
        $car = $this->car(100);
        $this->images($car, 8);
        $this->views($car, 200, 60);   // muitas vistas, mas há 60 dias
        $this->interaction($car, 'whatsapp_click', 60, 5);
        $this->lead($car, 50);         // 2 leads antigas
        $this->lead($car, 55);

        // Motor antigo (totais desde sempre): 200 vistas e 2 leads → nada.
        $this->assertSame([], app(CarIssueEngine::class)->getImmediateActions($this->company->id));

        // Motor novo (30 dias): 0 vistas e 0 leads → parada.
        $rec = $this->hub()->recommendations($this->company)['recommendations'][0];
        $this->assertSame('dead_stock', $rec['evidence']['issue_type']);
        $this->assertSame(0, $rec['evidence']['signals']['views']);
        $this->assertSame(0, $rec['evidence']['signals']['leads']);
    }

    public function test_price_rule_never_fires_on_an_approximate_comparison(): void
    {
        $car = $this->car(10, ['vehicle_type' => 'motorhome', 'price_gross' => 26000]);
        $this->aggregate($car, 20000, 'high', true);   // +30%, mas cascata antiga (pode ter largado o modelo)
        $this->views($car, 100);
        $this->interaction($car, 'whatsapp_click', 1, 10);
        $this->images($car, 10);

        $issues = collect(app(\App\Recommendations\RecommendationEngine::class)->forCompany($this->company, 'automotive')['recommendations'])
            ->pluck('evidence.issue_type');

        $this->assertNotContains('price_above_market', $issues->all());
        // O motor antigo recomendaria baixar o preço.
        $this->assertSame('price_above_market', app(CarIssueEngine::class)->getImmediateActions($this->company->id)[0]['issue_type']);
    }

    public function test_dead_stock_uses_the_threshold_of_the_vehicle_type(): void
    {
        // O motor antigo não lia vehicle_type (coluna fora do select) e usava sempre
        // 60 dias. O migrado usa o limiar do tipo: 45 dias para um carro.
        $car = $this->car(50);
        $this->images($car, 8);
        $this->views($car, 100);
        $this->interaction($car, 'whatsapp_click', 1, 5);

        $this->assertSame([], app(CarIssueEngine::class)->getImmediateActions($this->company->id));
        $rec = $this->hub()->recommendations($this->company)['recommendations'][0];
        $this->assertSame('dead_stock', $rec['evidence']['issue_type']);
        $this->assertSame(45, $rec['evidence']['threshold_days']);
    }

    public function test_window_is_configurable_per_company(): void
    {
        $car = $this->car(100);
        $this->images($car, 8);
        $this->views($car, 100, 45);
        $this->interaction($car, 'whatsapp_click', 45, 5);
        DB::table('company_recommendation_settings')->insert([
            'company_id' => $this->company->id, 'rule_key' => 'automotive_dead_stock', 'enabled' => true,
            'params' => json_encode(['window_days' => 60]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rec = collect($this->hub()->recommendations($this->company)['recommendations'])->firstWhere('evidence.issue_type', 'dead_stock');
        $this->assertSame(60, $rec['evidence']['signals']['window_days']);
        $this->assertSame(100, $rec['evidence']['signals']['views']);
    }

    // ── 3. FUNIL ──────────────────────────────────────────────────────────────

    public function test_extended_view_counts_contacts_and_keeps_existing_columns(): void
    {
        $car = $this->car(10);
        foreach (['whatsapp_click', 'call_click', 'show_phone', 'copy_phone', 'form_open', 'form_start'] as $type) {
            $this->interaction($car, $type);
        }
        DB::table('car_interactions')->insert(['company_id' => $car->company_id, 'car_id' => $car->id, 'interaction_type' => 'scroll',
            'visitor_id' => 'v-scroll', 'session_id' => 's-scroll', 'meta' => json_encode(['scroll_pct' => 80]), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

        $row = DB::table('car_funnel_metrics_daily')->where('car_id', $car->id)->first();

        $this->assertSame(4, (int) $row->contacts);         // whatsapp + chamada + ver + copiar telefone
        $this->assertSame(1, (int) $row->whatsapp_clicks);   // colunas antigas iguais
        $this->assertSame(2, (int) $row->form_opens);
        $this->assertSame(1, (int) $row->call_clicks);
        $this->assertSame(2, (int) $row->phone_reveals);
        $this->assertEqualsWithDelta(80.0, (float) $row->scroll, 0.01);
    }

    public function test_funnel_per_car_with_window_paid_spend_and_cpl(): void
    {
        $withLeads = $this->car(20);
        $this->views($withLeads, 30, 3);
        $this->views($withLeads, 50, 20);                // fora dos 14 dias
        $this->interaction($withLeads, 'call_click', 2);
        $this->interaction($withLeads, 'form_open', 2);  // não é contacto
        $this->lead($withLeads, 2, 'paid');
        $this->lead($withLeads, 3, 'paid');
        $this->lead($withLeads, 4, 'direct');
        $this->adSpend('A1', "Golf [id:{$withLeads->id}]", 30.0, 2);

        $noLead = $this->car(15);
        $this->adSpend('A2', "Polo [id:{$noLead->id}]", 12.5, 2);

        $sold = $this->car(60, ['status' => 'sold', 'sold_at' => now()->subDays(5)]);
        $this->car(80, ['status' => 'sold', 'sold_at' => now()->subDays(40)]);   // fora da janela

        $f = $this->hub()->funnel($this->company->id, 14);
        $rows = collect($f['rows'])->keyBy('car_id');

        $r = $rows[$withLeads->id];
        $this->assertSame([30, 1, 3, 2], [$r['views'], $r['contacts'], $r['leads'], $r['paid_leads']]);
        $this->assertSame(30.0, $r['paid_spend']);
        $this->assertSame(15.0, $r['cpl']);
        $this->assertSame('ok', $r['cpl_state']);
        $this->assertSame('active', $r['ad_status']['status']);

        $n = $rows[$noLead->id];
        $this->assertNull($n['cpl']);                                 // nunca ∞
        $this->assertSame('spend_without_lead', $n['cpl_state']);

        $this->assertTrue($rows[$sold->id]['sold']);
        $this->assertSame(3, count($f['rows']));                      // a vendida há 40 dias fica de fora
        $this->assertSame(1, $f['totals']['sales']);
        $this->assertSame(42.5, $f['totals']['paid_spend']);
        $this->assertSame(21.25, $f['totals']['cpl']);

        $f30 = $this->hub()->funnel($this->company->id, 30);
        $this->assertSame(80, collect($f30['rows'])->firstWhere('car_id', $withLeads->id)['views']);
    }

    public function test_funnel_is_paginated_in_the_backend_by_days_in_stock(): void
    {
        foreach ([5, 50, 20, 80, 10, 35, 60, 15, 25, 45, 70, 30] as $d) {
            $this->car($d);
        }
        $this->views($this->company->cars()->orderBy('id')->first(), 4, 2);

        $p1 = $this->hub()->funnel($this->company->id, 30);
        $this->assertCount(10, $p1['rows']);   // 10 por omissão
        $this->assertSame([80, 70, 60, 50, 45, 35, 30, 25, 20, 15], array_column($p1['rows'], 'days_in_stock'));
        $this->assertSame(['current_page' => 1, 'per_page' => 10, 'total' => 12, 'last_page' => 2, 'from' => 1, 'to' => 10], $p1['pagination']);
        $this->assertSame(12, $p1['totals']['cars']);   // totais de todas as viaturas, não da página
        $this->assertSame(4, $p1['totals']['views']);

        $p2 = $this->hub()->funnel($this->company->id, 30, null, 2);
        $this->assertSame([10, 5], array_column($p2['rows'], 'days_in_stock'));
        $this->assertSame(11, $p2['pagination']['from']);

        $url = "/api/v1/companies/{$this->company->id}/automotive-hub/funnel?days=14";
        $this->actingAs($this->user, 'sanctum')->getJson("{$url}&page=2&per_page=5")->assertOk()
            ->assertJsonPath('data.pagination.current_page', 2)->assertJsonPath('data.pagination.last_page', 3)
            ->assertJsonCount(5, 'data.rows');
        $this->actingAs($this->user, 'sanctum')->getJson("{$url}&per_page=500")->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->getJson("{$url}&page=99")->assertOk()->assertJsonPath('data.pagination.current_page', 2);
    }

    public function test_cpl_never_divides_by_zero(): void
    {
        $this->assertSame(['cpl' => null, 'cpl_state' => 'no_spend'], AutomotiveHubService::cpl(0.0, 0));
        $this->assertSame(['cpl' => null, 'cpl_state' => 'no_spend'], AutomotiveHubService::cpl(0.0, 3));
        $this->assertSame(['cpl' => null, 'cpl_state' => 'spend_without_lead'], AutomotiveHubService::cpl(50.0, 0));
        $this->assertSame(['cpl' => 25.0, 'cpl_state' => 'ok'], AutomotiveHubService::cpl(50.0, 2));
    }

    public function test_funnel_price_column_says_exact_or_approximate(): void
    {
        $car = $this->car(10, ['vehicle_type' => 'motorhome', 'price_gross' => 24000]);
        $this->aggregate($car, 20000, 'high', true);
        $widened = $this->car(10, ['price_gross' => 24000]);
        $this->aggregate($widened, 20000, 'high', true);

        $rows = collect($this->hub()->funnel($this->company->id, 30)['rows'])->keyBy('car_id');
        $this->assertSame('exact', $rows[$widened->id]['price']['comparison']);
        $this->assertTrue($rows[$widened->id]['price']['criteria_widened']);
        $row = $rows[$car->id];

        $this->assertSame('approximate', $row['price']['comparison']);
        $this->assertSame('approximate_comparison', $row['price']['display_position']);
    }

    // ── 4. AVISOS + ENDPOINTS + TENANCY ─────────────────────────────────────

    public function test_endpoints_enforce_tenancy_and_return_hub_with_warnings(): void
    {
        $this->car(10);
        $this->adSpend('A3', 'Golf [id:999999]', 5.0);
        $base = "/api/v1/companies/{$this->company->id}/automotive-hub";

        $this->actingAs($this->otherUser, 'sanctum')->getJson($base)->assertStatus(403);
        $this->actingAs($this->otherUser, 'sanctum')->getJson("{$base}/funnel?days=14")->assertStatus(403);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($base)->assertOk();
        $this->assertSame(1, $res->json('data.summary.stock.total_cars'));
        $this->assertSame(1, $res->json('data.warnings.invalid_tags.count'));
        $this->assertSame(['999999'], $res->json('data.warnings.invalid_tags.items.0.invalid_ids'));
        $this->assertIsArray($res->json('data.recommendations.recommendations'));

        $this->actingAs($this->user, 'sanctum')->getJson("{$base}/funnel?days=14")->assertOk()->assertJsonPath('data.days', 14);
        $this->actingAs($this->user, 'sanctum')->getJson("{$base}/funnel?days=7")->assertStatus(422);
    }
}
