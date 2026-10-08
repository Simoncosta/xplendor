<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CmReservationChannelDaily;
use App\Models\CmReservationLeadtimeDaily;
use App\Models\Company;
use App\Models\EditorialOwnAnchor;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinDailySale;
use App\Models\PingwinHourlySale;
use App\Models\PingwinHourlySalesDay;
use App\Models\PingwinItemSale;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use App\Models\RestaurantDataQuality;
use App\Models\RestaurantFamilyCategory;
use App\Models\RestaurantSignal;
use App\Services\CompanyModuleService;
use App\Services\Restaurant\RestaurantSignalService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — F3: o motor dos sinais de "O que publicar e quando" (documents/PINGWIN-F3-DESENHO.md).
 * Hoje é quinta-feira, 08/10/2026; o último dia fechado é quarta-feira, 07/10.
 */
class RestaurantSignalsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private PingwinLocation $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 10:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009900', 'fiscal_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        $this->loc = $this->location('Baixa', '1', '2026-01-10');
    }

    private function location(string $name, string $store, string $opened): PingwinLocation
    {
        return PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => $store, 'display_name' => $name, 'is_active' => true, 'opened_on' => $opened]);
    }

    /** Vendas por artigo lidas (estado "ok") em todos os dias do intervalo. */
    private function readDays(PingwinLocation $l, string $from, string $to): void
    {
        foreach (CarbonPeriod::create($from, $to) as $d) {
            PingwinItemSalesDay::firstOrCreate(['location_id' => $l->id, 'business_date' => $d->toDateString()], ['company_id' => $this->company->id, 'status' => 'ok']);
        }
    }

    /** Um artigo vendido num dia (cêntimos sem IVA). */
    private function sale(PingwinLocation $l, string $date, string $product, float $qty, int $net, string $family = 'F1', ?string $name = null): void
    {
        PingwinItemSale::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date, 'product_pingwin_id' => $product,
            'product_name' => $name ?? "Artigo {$product}", 'family_pingwin_id' => $family, 'family_path' => "Família \\ Teste \\ {$family}",
            'quantity' => $qty, 'net_cents' => $net]);
    }

    /** Espalha $qty unidades e $net cêntimos por $days dias a partir de $from (para os totais por janela). */
    private function spread(PingwinLocation $l, string $from, int $days, string $product, int $qty, int $net, string $family = 'F1', ?string $name = null): void
    {
        $per = intdiv($qty, $days);
        $rest = $qty - $per * $days;
        for ($i = 0; $i < $days; $i++) {
            $q = $per + ($i < $rest ? 1 : 0);
            if ($q > 0) {
                $this->sale($l, CarbonImmutable::parse($from)->addDays($i)->toDateString(), $product, $q, (int) round($net * $q / $qty), $family, $name);
            }
        }
    }

    private function confirm(string $family, string $category): void
    {
        $row = RestaurantFamilyCategory::firstOrNew(['company_id' => $this->company->id, 'family_pingwin_id' => $family]);
        $row->family_path = "Família \\ Teste \\ {$family}";
        $row->forceFill(['category' => $category, 'confirmed_at' => now()])->save();
    }

    private function compute(): array
    {
        return app(RestaurantSignalService::class)->compute($this->company->id);
    }

    private function signal(string $key): ?RestaurantSignal
    {
        return RestaurantSignal::where('company_id', $this->company->id)->where('signal_key', $key)->first();
    }

    // ── S1: os mais vendidos ───────────────────────────────────────────────────

    public function test_top_items_rank_by_value_with_minimum_and_without_excluded_categories(): void
    {
        $this->readDays($this->loc, '2026-07-14', '2026-10-07');
        $this->spread($this->loc, '2026-09-10', 28, 'A', 40, 50000, 'F1', 'Francesinha');
        $this->spread($this->loc, '2026-09-10', 28, 'B', 15, 20000, 'F1', 'Prego');
        $this->spread($this->loc, '2026-09-10', 28, 'C', 200, 0, 'F1', 'bem passado');        // modificador a 0 €
        $this->spread($this->loc, '2026-09-10', 28, 'D', 50, 60000, 'UBER', 'Comida Uber');    // Entrega
        $this->spread($this->loc, '2026-09-10', 28, 'E', 50, 70000, 'TAXA', 'Taxa de serviço'); // Excluir
        $this->confirm('UBER', 'entrega');
        $this->confirm('TAXA', 'excluir');
        // Família por confirmar que as regras sugerem como Entrega: fica de fora por precaução.
        $this->spread($this->loc, '2026-09-10', 28, 'M', 90, 80000, 'UEATS', 'Molho Extra Uber');
        PingwinItemSale::where('family_pingwin_id', 'UEATS')->update(['family_path' => 'Família \\ Uber Eats \\ Comida']);

        $this->compute();

        $s = $this->signal("top_items:{$this->loc->id}");
        $this->assertSame(['Francesinha', 'Prego'], array_column($s->numbers['items'], 'name'));
        $this->assertSame(['alta', 'media'], array_column($s->numbers['items'], 'confidence'));
        $this->assertSame('alta', $s->confidence);
        $this->assertSame('Nas últimas 4 semanas, Francesinha foi o artigo que mais vendeu na loja Baixa: 40 unidades, 500 € sem IVA (17,9% das vendas da loja).', $s->sentence);
        $uber = RestaurantFamilyCategory::where('family_pingwin_id', 'UEATS')->first();
        $this->assertSame('entrega', $uber->suggested_category); // sugerida pelas regras
        $this->assertNull($uber->category);                      // nada fica confirmado
    }

    // ── S2: em subida e em descida ─────────────────────────────────────────────

    public function test_changes_need_volume_beat_the_store_and_mention_special_days(): void
    {
        $this->readDays($this->loc, '2026-07-14', '2026-10-07');
        // 4 semanas anteriores (13/08 a 09/09) e últimas 4 semanas (10/09 a 07/10).
        $this->spread($this->loc, '2026-08-13', 28, 'U', 30, 30000, 'F1', 'Copo Sangria');
        $this->spread($this->loc, '2026-09-10', 28, 'U', 45, 45000, 'F1', 'Copo Sangria');   // mais 50%
        $this->spread($this->loc, '2026-08-13', 28, 'H', 50, 60000, 'F1', 'Hambúrguer');
        $this->spread($this->loc, '2026-09-10', 28, 'H', 80, 96000, 'F1', 'Hambúrguer');     // mais 60%, volume alto
        $this->spread($this->loc, '2026-08-13', 28, 'L', 50, 60000, 'F1', 'Lasanha');
        $this->spread($this->loc, '2026-09-10', 28, 'L', 30, 36000, 'F1', 'Lasanha');        // menos 40%
        $this->spread($this->loc, '2026-08-13', 28, 'S', 20, 30000, 'F1', 'Sopa');
        $this->spread($this->loc, '2026-09-10', 28, 'S', 24, 36000, 'F1', 'Sopa');           // mais 20%: não chega
        $this->spread($this->loc, '2026-08-13', 28, 'P', 10, 30000, 'F1', 'Pudim');
        $this->spread($this->loc, '2026-09-10', 28, 'P', 25, 75000, 'F1', 'Pudim');          // menos de 20 unidades antes
        // O resto da loja equilibra: a loja varia pouco.
        $this->spread($this->loc, '2026-08-13', 28, 'Z', 100, 1000000, 'F1', 'Resto');
        $this->spread($this->loc, '2026-09-10', 28, 'Z', 100, 913000, 'F1', 'Resto');

        $this->compute();

        $up = $this->signal("item_up:{$this->loc->id}:U");
        $this->assertSame('media', $up->confidence);
        $this->assertSame('Copo Sangria em destaque', $up->theme);
        $this->assertStringStartsWith('Copo Sangria vendeu 45 unidades nas últimas 4 semanas na loja Baixa, contra 30 nas 4 anteriores (mais 50%; a loja variou', $up->sentence);
        $this->assertStringContainsString('Inclui datas especiais: Assunção de Nossa Senhora, Implantação da República.', $up->sentence);
        $this->assertSame('alta', $this->signal("item_up:{$this->loc->id}:H")->confidence);
        $this->assertSame('Voltar a mostrar: Lasanha', $this->signal("item_down:{$this->loc->id}:L")->theme);
        $this->assertNull($this->signal("item_up:{$this->loc->id}:S"));
        $this->assertNull($this->signal("item_up:{$this->loc->id}:P"));
        $this->assertSame('2026-10-09', $up->suggested_date->toDateString());
    }

    public function test_an_item_growing_with_the_whole_store_is_not_a_change(): void
    {
        $this->readDays($this->loc, '2026-07-14', '2026-10-07');
        $this->spread($this->loc, '2026-08-13', 28, 'U', 30, 30000);
        $this->spread($this->loc, '2026-09-10', 28, 'U', 45, 45000);        // mais 50%
        $this->spread($this->loc, '2026-08-13', 28, 'Z', 100, 1000000);
        $this->spread($this->loc, '2026-09-10', 28, 'Z', 140, 1400000);     // a loja cresce perto de 40%

        $this->compute();

        $this->assertNull($this->signal("item_up:{$this->loc->id}:U"));
    }

    // ── S3: períodos fracos (ajuste 1: feriados e âncoras fora da média) ───────

    public function test_weak_day_excludes_holidays_and_anchor_dates_and_skips_closed_days(): void
    {
        $this->readDays($this->loc, '2026-07-14', '2026-10-07');
        // Por dia (sem vendas por hora): 1 000 € por dia, 600 € às terças, segunda fechada.
        foreach (CarbonPeriod::create('2026-08-13', '2026-10-07') as $d) {
            $net = match ($d->dayOfWeekIso) { 1 => 0, 2 => 60000, default => 100000 };
            PingwinDailySale::create(['company_id' => $this->company->id, 'location_id' => $this->loc->id, 'business_date' => $d->toDateString(), 'net_cents' => $net]);
        }
        // Uma terça com festa (âncora própria): se contasse, a terça deixava de ser fraca.
        PingwinDailySale::where('location_id', $this->loc->id)->where('business_date', '2026-09-22')->update(['net_cents' => 300000]);
        EditorialOwnAnchor::create(['company_id' => $this->company->id, 'title' => 'Festa do bairro', 'rule_type' => 'fixa', 'month' => 9, 'day' => 22]);

        $this->compute();

        $s = $this->signal("weak_period:{$this->loc->id}:2:dia");
        $this->assertNotNull($s, 'A terça devia ser fraca sem o dia da festa.');
        $this->assertSame(7, $s->numbers['occurrences']);
        $this->assertSame('media', $s->confidence); // 7 ocorrências: menos de 8
        $this->assertContains('2026-09-22', $s->sample['special_days_excluded']);
        $this->assertContains('2026-10-05', $s->sample['special_days_excluded']); // feriado nacional
        $this->assertSame('2026-10-11', $s->suggested_date->toDateString()); // terça 13/10, 2 dias antes
        $this->assertSame('As terças na loja Baixa ficaram 26% abaixo da média dos dias da semana (média de 600 € contra 815 €, em 7 terças). Sem dados de antecedência das reservas desta loja: sugestão de publicar no domingo, 11/10.', $s->sentence);
        $this->assertNull($this->signal("weak_period:{$this->loc->id}:1:dia")); // segunda fechada: não é fraca
    }

    // ── S3 por turno (ajuste 2: a tarde só com 10% das vendas) ─────────────────

    private function hourlyStore(PingwinLocation $l, int $afternoon, array $overrides = []): void
    {
        foreach (CarbonPeriod::create('2026-08-13', '2026-10-07') as $d) {
            $date = $d->toDateString();
            PingwinHourlySalesDay::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date, 'status' => 'ok']);
            $hours = [13 => 50000, 17 => $afternoon, 21 => 50000];
            foreach ($overrides[$d->dayOfWeekIso] ?? [] as $h => $v) {
                $hours[$h] = $v;
            }
            foreach ($hours as $h => $v) {
                PingwinHourlySale::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date, 'hour' => $h, 'net_cents' => $v]);
            }
        }
    }

    public function test_weak_shifts_and_the_afternoon_only_where_it_weighs_10_percent(): void
    {
        $other = $this->location('Costa Cabral', '2', '2026-01-10');
        foreach ([$this->loc, $other] as $l) {
            $this->readDays($l, '2026-07-14', '2026-10-07');
        }
        // Baixa: tarde pequena (2%) e fraca à quarta; almoço de quinta fraco.
        $this->hourlyStore($this->loc, 2000, [3 => [17 => 200], 4 => [13 => 20000]]);
        // Costa Cabral: tarde com peso (13%) e fraca à quarta.
        $this->hourlyStore($other, 15000, [3 => [17 => 5000]]);

        $this->compute();

        $this->assertNotNull($this->signal("weak_period:{$this->loc->id}:4:almoco"));
        $this->assertNull($this->signal("weak_period:{$this->loc->id}:3:tarde"));       // tarde abaixo de 10%: não conta
        $s = $this->signal("weak_period:{$other->id}:3:tarde");
        $this->assertNotNull($s);
        $this->assertSame('Período fraco: tarde de quarta (Costa Cabral)', $s->title);
        $this->assertStringStartsWith('As tardes de quarta na loja Costa Cabral ficaram', $s->sentence);
        $this->assertStringContainsString('abaixo da média das tardes da semana', $s->sentence);
        $this->assertSame('alta', $s->confidence); // 8 quartas, todas abaixo
        $this->assertSame('hours', $s->numbers['mode']);
    }

    // ── S4: artigos parados ────────────────────────────────────────────────────

    public function test_stale_items_need_a_fresh_catalog_and_skip_excluded_or_inactive(): void
    {
        $this->readDays($this->loc, '2026-07-14', '2026-10-07');
        foreach (['X' => 'F1', 'Y' => 'UBER', 'W' => 'F1'] as $product => $family) {
            $this->spread($this->loc, '2026-07-14', 56, $product, 20, 30000, $family, "Artigo {$product}");
        }
        $this->spread($this->loc, '2026-09-08', 30, 'Z', 30, 30000); // continua a vender
        $this->confirm('UBER', 'entrega');
        foreach (['X' => true, 'Y' => true, 'W' => false, 'Z' => true] as $product => $active) {
            PingwinCatalogItem::create(['company_id' => $this->company->id, 'pingwin_id' => $product, 'description' => $product, 'is_active' => $active, 'forsale' => true, 'synced_at' => now()->subDays(20)]);
        }

        $this->compute();
        $this->assertNull($this->signal("stale_item:{$this->loc->id}:X"));
        $avail = RestaurantDataQuality::where('company_id', $this->company->id)->value('signals_availability');
        $this->assertStringContainsString('catálogo lido nos últimos 14 dias', $avail[0]['signals']['stale_items']['reason']);

        PingwinCatalogItem::query()->update(['synced_at' => now()->subDay()]);
        $this->compute();

        $s = $this->signal("stale_item:{$this->loc->id}:X");
        $this->assertSame('media', $s->confidence);
        // 20 unidades espalhadas por 56 dias: a última venda foi a 02/08 (67 dias até hoje).
        $this->assertSame('Artigo X não vende há 67 dias na loja Baixa; nas 8 semanas anteriores vendeu 20 unidades.', $s->sentence);
        $this->assertNull($this->signal("stale_item:{$this->loc->id}:Y")); // Entrega
        $this->assertNull($this->signal("stale_item:{$this->loc->id}:W")); // inativo no catálogo
        $this->assertNull($this->signal("stale_item:{$this->loc->id}:Z")); // ainda vende
    }

    // ── S5 e S6: antecedência e canais ─────────────────────────────────────────

    public function test_lead_time_channels_and_the_publish_date_of_weak_periods(): void
    {
        $this->readDays($this->loc, '2026-07-14', '2026-10-07');
        foreach (CarbonPeriod::create('2026-08-13', '2026-10-07') as $d) {
            PingwinDailySale::create(['company_id' => $this->company->id, 'location_id' => $this->loc->id, 'business_date' => $d->toDateString(),
                'net_cents' => match ($d->dayOfWeekIso) { 2 => 60000, 6 => 200000, default => 100000 }]);
        }
        $row = fn ($model, $col, $val, $n) => $model::create(['company_id' => $this->company->id, 'location_id' => $this->loc->id, 'business_date' => '2026-09-30', $col => $val, 'reservations_count' => $n, 'guests_total' => $n * 2]);
        $row(CmReservationLeadtimeDaily::class, 'bucket', 'same_day', 120);
        $row(CmReservationLeadtimeDaily::class, 'bucket', 'd1_2', 60);
        $row(CmReservationLeadtimeDaily::class, 'bucket', 'd3_7', 20);
        $row(CmReservationChannelDaily::class, 'channel', 'online', 120);
        $row(CmReservationChannelDaily::class, 'channel', 'telefone', 50);
        $row(CmReservationChannelDaily::class, 'channel', 'walk in', 30);

        $this->compute();

        $lead = $this->signal("lead_time:{$this->loc->id}");
        $this->assertSame('alta', $lead->confidence);
        $this->assertSame('Na loja Baixa, 60% das reservas são feitas no próprio dia e 30% com 1 a 2 dias de antecedência (últimos 90 dias, 200 reservas). Uma publicação para sábado chega antes de 80% das reservas se sair até quinta-feira (2 dias antes).', $lead->sentence);
        $this->assertSame('Das reservas dos últimos 90 dias na loja Baixa, 60% chegaram por online, 25% por telefone e 15% foram walk-ins.', $this->signal("channels:{$this->loc->id}")->sentence);
        // A antecedência mais frequente é no próprio dia: publicar 1 dia antes (terça 13/10 → segunda 12/10).
        $weak = $this->signal("weak_period:{$this->loc->id}:2:dia");
        $this->assertSame('2026-10-12', $weak->suggested_date->toDateString());
        $this->assertStringContainsString('A maior parte das reservas da loja é feita no próprio dia: sugestão de publicar na segunda-feira, 12/10.', $weak->sentence);
    }

    public function test_few_reservations_give_no_lead_time_or_channels(): void
    {
        $this->readDays($this->loc, '2026-07-14', '2026-10-07');
        CmReservationLeadtimeDaily::create(['company_id' => $this->company->id, 'location_id' => $this->loc->id, 'business_date' => '2026-09-30', 'bucket' => 'same_day', 'reservations_count' => 79]);

        $this->compute();

        $this->assertNull($this->signal("lead_time:{$this->loc->id}"));
        $avail = RestaurantDataQuality::where('company_id', $this->company->id)->value('signals_availability');
        $this->assertSame('Precisa de pelo menos 80 reservas lidas nos últimos 90 dias (CoverManager).', $avail[0]['signals']['lead_time']['reason']);
    }

    // ── Por categoria: só com as categorias confirmadas ────────────────────────

    public function test_category_signals_and_delivery_need_every_family_confirmed(): void
    {
        $this->readDays($this->loc, '2026-07-14', '2026-10-07');
        $this->spread($this->loc, '2026-09-10', 28, 'A', 40, 60000, 'PRATOS', 'Francesinha');
        $this->spread($this->loc, '2026-09-10', 28, 'B', 40, 30000, 'CERV', 'Fino');
        $this->spread($this->loc, '2026-09-10', 28, 'C', 10, 10000, 'UBER', 'Comida Uber');
        $this->confirm('PRATOS', 'pratos');
        $this->confirm('CERV', 'cerveja');

        $this->compute();
        $this->assertNull($this->signal("top_categories:{$this->loc->id}"));
        $avail = RestaurantDataQuality::where('company_id', $this->company->id)->value('signals_availability');
        $this->assertSame('Confirme as categorias das famílias (1 por confirmar nesta loja).', $avail[0]['signals']['top_categories']['reason']);

        $this->confirm('UBER', 'entrega');
        $this->compute();

        $cat = $this->signal("top_categories:{$this->loc->id}");
        $this->assertSame('Nas últimas 4 semanas, na loja Baixa, a categoria com mais peso nas vendas foi Pratos principais (60%), seguida de Cerveja (30%).', $cat->sentence);
        $this->assertSame('Nas últimas 4 semanas, a entrega representou 10% das vendas da loja Baixa (100 € sem IVA).', $this->signal("delivery_share:{$this->loc->id}")->sentence);
    }

    // ── Poucos dados, interruptor e frescura ───────────────────────────────────

    public function test_a_new_store_gets_no_signals_and_knows_when_each_one_arrives(): void
    {
        $new = $this->location('Nova', '3', '2026-09-20');
        $this->readDays($new, '2026-09-20', '2026-10-07');
        $this->spread($new, '2026-09-20', 18, 'A', 40, 50000);

        $this->compute();

        $this->assertSame(0, RestaurantSignal::where('location_id', $new->id)->count());
        $avail = collect(RestaurantDataQuality::where('company_id', $this->company->id)->value('signals_availability'))->firstWhere('location_id', $new->id);
        $this->assertSame('2026-10-18', $avail['signals']['top_items']['from']);
        $this->assertSame('2026-11-15', $avail['signals']['changes']['from']);
        $this->assertSame('Precisa de 8 semanas de vendas desta loja.', $avail['signals']['changes']['reason']);
        $this->assertSame('2026-11-01', $avail['signals']['weak_periods']['from']);
        $this->assertSame('2027-09-20', $avail['yoy_from']);
        // Nenhum sinal de confiança baixa é guardado.
        $this->assertSame(0, RestaurantSignal::whereNotIn('confidence', ['alta', 'media'])->count());
    }

    public function test_switch_off_computes_nothing_and_fresh_signals_are_reused(): void
    {
        $this->readDays($this->loc, '2026-07-14', '2026-10-07');
        $this->spread($this->loc, '2026-09-10', 28, 'A', 40, 50000);
        $this->company->forceFill(['pingwin_item_sales_enabled' => false])->save();
        $this->assertFalse($this->compute()['computed']);
        $this->assertSame(0, RestaurantSignal::count());

        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        $service = app(RestaurantSignalService::class);
        $service->ensureFresh($this->company->id);
        $first = RestaurantDataQuality::where('company_id', $this->company->id)->value('signals_computed_at');
        $this->travel(2)->hours();
        $service->ensureFresh($this->company->id);
        $this->assertEquals($first, RestaurantDataQuality::where('company_id', $this->company->id)->value('signals_computed_at'));
        $this->travel(23)->hours();
        $service->ensureFresh($this->company->id);
        $this->assertNotEquals($first, RestaurantDataQuality::where('company_id', $this->company->id)->value('signals_computed_at'));
    }
}
