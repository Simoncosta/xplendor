<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiRequest;
use App\Models\CmReservationChannelDaily;
use App\Models\Company;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\PingwinHourlySale;
use App\Models\PingwinHourlySalesDay;
use App\Models\PingwinItemSale;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use App\Models\RestaurantCompassText;
use App\Models\RestaurantDataQuality;
use App\Models\RestaurantFamilyCategory;
use App\Models\RestaurantSignal;
use App\Models\RestaurantSignalAction;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Restaurant\RestaurantCompassService;
use App\Services\Restaurant\RestaurantHeatmapService;
use App\Services\Restaurant\RestaurantSignalService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesAi;
use Tests\TestCase;

/**
 * Bússola: a escolha e o agrupamento das jogadas, nenhuma data passada, a mesma janela no mapa
 * e nas jogadas, a IA simulada e o modelo de frase sem IA, o verificador do português, as
 * categorias "Excluir" e "Entrega" fora de tudo, a frase da loja em descida, os canais em nomes
 * simples, e criar publicação e sugerir texto a partir de uma jogada.
 * Hoje é quinta-feira, 08/10/2026; o último dia fechado é quarta-feira, 07/10.
 */
class RestaurantCompassTest extends TestCase
{
    use RefreshDatabase;
    use FakesAi;

    private Company $company;
    private PingwinLocation $baixa;
    private PingwinLocation $costa;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 10:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009930', 'fiscal_name' => 'Yuko Lda', 'trade_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        app(CompanyModuleService::class)->enable($this->company->id, 'linha_editorial');
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        $this->baixa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '1', 'display_name' => 'Baixa', 'is_active' => true, 'opened_on' => '2026-01-10']);
        $this->costa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '2', 'display_name' => 'Costa', 'is_active' => true, 'opened_on' => '2026-01-10']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    // ── Auxiliares ───────────────────────────────────────────────────────────

    private function signal(PingwinLocation $l, string $key, string $type, string $confidence, array $numbers, string $sentence = 'Frase.', array $sample = []): RestaurantSignal
    {
        return RestaurantSignal::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'type' => $type, 'signal_key' => $key,
            'kind' => in_array($type, ['weak_period', 'item_up', 'item_down', 'stale_item'], true) ? 'suggestion' : 'info',
            'confidence' => $confidence, 'title' => "Título {$key}", 'sentence' => $sentence, 'numbers' => $numbers, 'sample' => $sample,
            'theme' => null, 'suggested_date' => '2026-10-01', 'priority' => 0, 'computed_at' => now()]);
    }

    private function weak(PingwinLocation $l, int $wd, string $shift, string $conf, int $pct): RestaurantSignal
    {
        return $this->signal($l, "weak_period:{$l->id}:{$wd}:{$shift}", 'weak_period', $conf,
            ['weekday' => $wd, 'shift' => $shift, 'mode' => 'hours', 'avg_cents' => 100 - $pct, 'mean_cents' => 100, 'pct_below' => $pct, 'offset_days' => 1]);
    }

    private function item(PingwinLocation $l, string $type, string $product, string $name, string $conf, int $before, int $now, float $storeVar = 0.0): RestaurantSignal
    {
        $var = round(($now - $before) / $before * 100, 1);

        return $this->signal($l, "{$type}:{$l->id}:{$product}", $type, $conf, ['product_id' => $product, 'name' => $name, 'qty_now' => $now, 'qty_before' => $before,
            'net_now_cents' => $now * 1000, 'net_before_cents' => $before * 1000, 'variation_pct' => $var, 'store_variation_pct' => $storeVar]);
    }

    /** Vendas por artigo nas duas janelas (para os factos: variação da loja e categorias). */
    private function sales(PingwinLocation $l, string $product, string $name, string $family, int $before, int $now, int $netEach = 1000): void
    {
        foreach (CarbonPeriod::create('2026-08-13', '2026-10-07') as $d) {
            PingwinItemSalesDay::firstOrCreate(['location_id' => $l->id, 'business_date' => $d->toDateString()], ['company_id' => $this->company->id, 'status' => 'ok']);
        }
        foreach ([['2026-08-20', $before], ['2026-09-20', $now]] as [$date, $qty]) {
            PingwinItemSale::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date, 'product_pingwin_id' => $product,
                'product_name' => $name, 'family_pingwin_id' => $family, 'family_path' => "Família \\ Comidas \\ {$family}", 'quantity' => $qty, 'net_cents' => $qty * $netEach]);
        }
    }

    private function confirm(string $family, string $category): void
    {
        $row = RestaurantFamilyCategory::firstOrNew(['company_id' => $this->company->id, 'family_pingwin_id' => $family]);
        $row->family_path = "Família \\ Comidas \\ {$family}";
        $row->forceFill(['category' => $category, 'confirmed_at' => now()])->save();
    }

    /** Sinais "acabados de calcular" (a Bússola não recalcula). */
    private function fresh(): void
    {
        RestaurantDataQuality::updateOrCreate(['company_id' => $this->company->id], ['signals_computed_at' => now(), 'signals_availability' => []]);
    }

    private function payload(?int $locationId = null, bool $summary = false): array
    {
        return app(RestaurantCompassService::class)->payload($this->company->id, $locationId, $summary);
    }

    // ── Escolha e agrupamento ────────────────────────────────────────────────

    public function test_plays_group_what_says_the_same_and_pick_one_per_type_by_strength(): void
    {
        $this->sales($this->baixa, 'S1', 'Cheesecake', 'DOCES', 100, 40);
        $this->sales($this->baixa, 'S2', 'Mousse', 'DOCES', 80, 30);
        $this->sales($this->costa, 'P1', 'Prego', 'PRATOS', 60, 30);
        $this->confirm('DOCES', 'sobremesas');
        $this->confirm('PRATOS', 'pratos');
        // Quarta e terça (duas lojas, vários turnos) = "o meio da semana"; sábado fica à parte.
        $this->weak($this->baixa, 3, 'almoco', 'alta', 30);
        $this->weak($this->costa, 3, 'jantar', 'media', 25);
        $this->weak($this->costa, 2, 'almoco', 'media', 21);
        $this->weak($this->baixa, 6, 'jantar', 'media', 45);
        // Duas sobremesas a descer = uma jogada; o Prego isolado; um artigo a subir em média.
        $this->item($this->baixa, 'item_down', 'S1', 'Cheesecake', 'alta', 100, 40, -10);
        $this->item($this->baixa, 'item_down', 'S2', 'Mousse', 'media', 80, 30, -10);
        $this->item($this->costa, 'item_down', 'P1', 'Prego', 'media', 60, 30, -5);
        $this->item($this->costa, 'item_up', 'U1', 'Sangria', 'media', 30, 45, -5);
        $this->signal($this->baixa, 'stale_item:1:Z', 'stale_item', 'media', ['product_id' => 'Z', 'name' => 'Piu-Piu', 'days_without_sales' => 40, 'qty_before' => 20, 'net_before_cents' => 20000]);
        $this->fresh();

        $plays = $this->payload(null, true)['plays'];

        $this->assertCount(3, $plays);
        // Confiança alta primeiro; entre as altas, o desvio maior (as sobremesas: 50 pontos além da loja; a quarta: 30%).
        $this->assertSame(['item_down', 'weak_period'], array_slice(array_column($plays, 'type'), 0, 2));
        $this->assertCount(3, array_unique(array_column($plays, 'type')), 'No máximo uma por tipo.');
        [$down, $weak] = $plays;
        $this->assertSame(['weak_period:midweek', 'Encher o meio da semana'], [$weak['key'], $weak['title']]);
        $this->assertSame(['Baixa', 'Costa'], $weak['locations']);
        $this->assertSame('−30%', $weak['number']['value']);
        $this->assertCount(3, $weak['bars']);
        $this->assertSame(3, count($weak['signal_keys']));
        $this->assertSame(['item_down:cat:sobremesas', 'Recuperar as sobremesas'], [$down['key'], $down['title']]);
        $this->assertSame(['Cheesecake (Baixa)', 'Mousse (Baixa)'], array_column($down['bars'], 'label'));
        // O terceiro: o esquecido (desvio maior) ganha ao artigo a subir (ambos em média).
        $this->assertSame(['stale_item:prod:Z', 'Voltar a mostrar Piu-Piu', '40 dias'], [$plays[2]['key'], $plays[2]['title'], $plays[2]['number']['value']]);

        // Uma só loja: o meio da semana só tem a quarta, que fica sozinha.
        $one = collect($this->payload($this->baixa->id, true)['plays'])->firstWhere('type', 'weak_period');
        $this->assertSame('Encher o almoço de quarta', $one['title']); // alta (30%) ganha à média de sábado (45%)

        // O mesmo artigo em duas lojas junta-se numa jogada.
        $groups = app(RestaurantCompassService::class)->candidateGroups(collect([
            $this->item($this->baixa, 'item_up', 'Q', 'Copo Sangria', 'alta', 30, 50),
            $this->item($this->costa, 'item_up', 'Q', 'Copo Sangria', 'media', 30, 45),
        ]), []);
        $this->assertSame('item_up:prod:Q', $groups['item_up'][0]['key']);
        $this->assertCount(2, $groups['item_up'][0]['members']);
    }

    public function test_ignored_signals_and_excluded_categories_never_enter_plays_or_rankings(): void
    {
        $this->sales($this->baixa, 'C1', 'Couvert', 'TAXA', 100, 160, 300);
        $this->sales($this->baixa, 'F1', 'Francesinha', 'FRANC', 200, 200, 1200);
        $this->confirm('TAXA', 'excluir');
        $this->confirm('FRANC', 'pratos');
        // Um sinal calculado antes de confirmar a categoria: fica de fora na mesma.
        $this->item($this->baixa, 'item_up', 'C1', 'Couvert', 'alta', 100, 160);
        $ignored = $this->weak($this->baixa, 3, 'almoco', 'alta', 30);
        RestaurantSignalAction::create(['company_id' => $this->company->id, 'signal_key' => $ignored->signal_key, 'action' => RestaurantSignalAction::IGNORED,
            'hidden_until' => '2026-11-01', 'user_id' => $this->admin->id]);
        $this->fresh();

        $p = $this->payload();
        $this->assertSame([], $p['plays']);
        $this->assertSame([], $p['blocks']['changes']['up']);
        $this->assertSame(['Francesinha'], array_column($p['blocks']['stars']['stores'][0]['items'], 'name'));
        $this->assertSame('Peso de FRANC', collect($p['top']['numbers'])->firstWhere('kind', 'family')['label']);
    }

    // ── Datas ────────────────────────────────────────────────────────────────

    public function test_when_is_computed_on_read_and_never_in_the_past(): void
    {
        $this->weak($this->baixa, 5, 'jantar', 'alta', 30); // sexta, a 1 dia: publicar hoje (quinta)
        $this->fresh();
        $play = $this->payload(null, true)['plays'][0];
        $this->assertSame(['2026-10-08', true, 'Publicar hoje', 'para a sexta-feira, 09/10'],
            [$play['when']['date'], $play['when']['is_today'], $play['when']['label'], $play['when']['target']]);

        // Dias depois, sem recalcular: a data guardada ficou para trás, a da Bússola não.
        $this->travelTo('2026-10-13 10:00:00');
        $this->fresh();
        $play = $this->payload(null, true)['plays'][0];
        $this->assertSame('2026-10-15', $play['when']['date']);
        $this->assertSame('Publicar na quinta-feira, 15/10', $play['when']['label']);

        $this->item($this->baixa, 'item_up', 'U', 'Sangria', 'alta', 30, 50);
        foreach ($this->payload(null, true)['plays'] as $p) {
            $this->assertGreaterThanOrEqual('2026-10-13', $p['when']['date']);
        }
        // Para todas as datas e antecedências da regra, nunca antes de hoje.
        foreach (range(1, 7) as $wd) {
            foreach ([1, 2, 7] as $offset) {
                [$target, $publish] = RestaurantSignalService::nextPublishDate($wd, $offset, CarbonImmutable::parse('2026-10-13'));
                $this->assertTrue($publish->gte(CarbonImmutable::parse('2026-10-13')));
                $this->assertSame($wd, $target->dayOfWeekIso);
            }
        }
    }

    // ── A mesma janela no mapa e nas jogadas ─────────────────────────────────

    public function test_the_grid_and_the_hourly_map_use_the_same_window_as_the_plays(): void
    {
        foreach (CarbonPeriod::create('2026-07-14', '2026-10-07') as $d) {
            PingwinItemSalesDay::firstOrCreate(['location_id' => $this->baixa->id, 'business_date' => $d->toDateString()], ['company_id' => $this->company->id, 'status' => 'ok']);
        }
        foreach (CarbonPeriod::create('2026-07-01', '2026-10-07') as $d) {
            $date = $d->toDateString();
            PingwinHourlySalesDay::create(['company_id' => $this->company->id, 'location_id' => $this->baixa->id, 'business_date' => $date, 'status' => 'ok']);
            $lunch = $d->dayOfWeekIso === 3 ? 10000 : 50000; // almoço de quarta fraco
            foreach ([13 => $lunch, 21 => 50000] as $h => $v) {
                PingwinHourlySale::create(['company_id' => $this->company->id, 'location_id' => $this->baixa->id, 'business_date' => $date, 'hour' => $h, 'net_cents' => $v]);
            }
        }
        app(RestaurantSignalService::class)->compute($this->company->id);

        $weak = RestaurantSignal::where('company_id', $this->company->id)->where('type', 'weak_period')->sole();
        $grid = app(RestaurantSignalService::class)->shiftGrid($this->company->id, $this->baixa);
        $map = app(RestaurantHeatmapService::class)->build($this->company->id, $this->baixa->id, 8, true);

        // 8 semanas até ontem (Lisboa), sem os dias especiais: as mesmas datas nos três.
        $this->assertSame(['2026-08-13', '2026-10-07'], [$weak->sample['from'], $weak->sample['to']]);
        $this->assertSame([$weak->sample['from'], $weak->sample['to']], [$grid['from'], $grid['to']]);
        $this->assertSame([$weak->sample['from'], $weak->sample['to']], [$map['from'], $map['to']]);
        $this->assertSame(count($weak->sample['special_days_excluded']), $grid['special_excluded']);
        $this->assertSame($grid['special_excluded'], $map['special_excluded']);
        $this->assertGreaterThan(0, $grid['special_excluded']); // 15/08 e 05/10 (feriados)
        $this->assertTrue($grid['shifts']['almoco']['days'][3]['weak']);
        $this->assertLessThanOrEqual(-70, $grid['shifts']['almoco']['days'][3]['pct_vs_mean']);
        $this->assertFalse($grid['shifts']['jantar']['days'][3]['weak']);
        // Os mapas de 12 semanas (separador Vendas) contam os dias especiais; o "ontem" é o de Lisboa.
        $twelve = app(RestaurantHeatmapService::class)->build($this->company->id, $this->baixa->id);
        $this->assertSame(['2026-07-16', '2026-10-07', 0], [$twelve['from'], $twelve['to'], $twelve['special_excluded']]);
        $this->travelTo('2026-10-08 23:30:00'); // 00:30 em Lisboa (verão): o dia fechado já é 08/10
        $this->assertSame('2026-10-08', app(RestaurantHeatmapService::class)->build($this->company->id, $this->baixa->id)['to']);
    }

    // ── "O quê": IA simulada, modelo de frase e verificador ──────────────────

    public function test_what_comes_from_the_ai_with_the_checker_or_falls_back_to_the_template(): void
    {
        $this->weak($this->baixa, 3, 'almoco', 'alta', 30);
        $this->item($this->baixa, 'item_up', 'U', 'Sangria', 'media', 30, 50);
        $this->fresh();

        // Sem IA configurada: modelo de frase por tipo, sem números.
        $this->assertSame(0, app(RestaurantCompassService::class)->generateTexts($this->company->id));
        $plays = collect($this->payload(null, true)['plays'])->keyBy('type');
        $this->assertSame('template', $plays['weak_period']['what']['source']);
        $this->assertSame('Publique uma proposta para o almoço de quarta na loja Baixa.', $plays['weak_period']['what']['text']);
        $this->assertSame('Dê destaque a Sangria numa publicação com uma boa fotografia.', $plays['item_up']['what']['text']);

        // IA simulada: uma frase aceite; a outra tem um número e fica o modelo.
        $this->configureAi();
        $this->fakeAi(['jogadas' => [
            ['chave' => 'weak_period:wd3', 'o_que' => 'Mostre um prato do dia ao almoço de quarta, com uma fotografia da sala.'],
            ['chave' => 'item_up:prod:U', 'o_que' => 'Publique a Sangria com 20% de desconto.'],
        ]]);
        $this->assertSame(1, app(RestaurantCompassService::class)->generateTexts($this->company->id));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && $r['output_config']['effort'] === 'low'
            && str_contains($r['messages'][0]['content'][0]['text'], 'weak_period:wd3'));
        $plays = collect($this->payload(null, true)['plays'])->keyBy('type');
        $this->assertSame(['ai', 'Mostre um prato do dia ao almoço de quarta, com uma fotografia da sala.'], [$plays['weak_period']['what']['source'], $plays['weak_period']['what']['text']]);
        $this->assertSame('template', $plays['item_up']['what']['source']);
        $this->assertSame(1, AiRequest::where('mode', 'bussola_jogadas')->where('status', 'done')->count());
    }

    public function test_the_portuguese_checker_rejects_brazilian_portuguese_and_causes(): void
    {
        $this->weak($this->baixa, 3, 'almoco', 'alta', 30);
        $this->fresh();
        $this->configureAi();
        // O português do Brasil nas duas tentativas (o verificador pede de novo uma vez): fica o modelo de frase.
        $this->fakeAi(['jogadas' => [['chave' => 'weak_period:wd3', 'o_que' => 'Você pode mostrar o almoço de quarta para a sua equipe.']]]);
        $this->assertSame(0, app(RestaurantCompassService::class)->generateTexts($this->company->id));
        Http::assertSentCount(2);
        $this->assertSame(0, RestaurantCompassText::count());
        $this->assertSame('template', $this->payload(null, true)['plays'][0]['what']['source']);
        $this->assertFalse(RestaurantCompassService::acceptableWhat('Publique porque as vendas caíram.'));
        $this->assertFalse(RestaurantCompassService::acceptableWhat('Publique a Sangria com 20% de desconto.'));
        $this->assertTrue(RestaurantCompassService::acceptableWhat('Mostre a Sangria numa fotografia ao fim da tarde.'));
    }

    // ── Topo, blocos e canais ────────────────────────────────────────────────

    public function test_top_says_once_that_the_store_went_down_and_keeps_the_items_that_beat_it(): void
    {
        foreach (['A' => [100, 60], 'B' => [80, 50], 'C' => [60, 40], 'D' => [50, 50]] as $p => [$b, $n]) {
            $this->sales($this->baixa, $p, "Artigo {$p}", 'F1', $b, $n);
        }
        $this->confirm('F1', 'pratos');
        $this->item($this->baixa, 'item_down', 'A', 'Artigo A', 'alta', 100, 60, -27);
        foreach (['same_day' => 150, 'd1_2' => 50] as $bucket => $n) {
            \App\Models\CmReservationLeadtimeDaily::create(['company_id' => $this->company->id, 'location_id' => $this->baixa->id, 'business_date' => '2026-09-30', 'bucket' => $bucket, 'reservations_count' => $n]);
        }
        $this->signal($this->baixa, "lead_time:{$this->baixa->id}", 'lead_time', 'alta', ['shares' => ['same_day' => 75.0, 'd1_2' => 25.0], 'total' => 200, 'mode' => 'same_day']);
        $this->signal($this->baixa, "channels:{$this->baixa->id}", 'channels', 'alta', ['channels' => [
            'software' => ['reservations' => 50, 'share_pct' => 25.0], 'terceros' => ['reservations' => 60, 'share_pct' => 30.0], 'walk in' => ['reservations' => 70, 'share_pct' => 35.0],
            'app-movil' => ['reservations' => 10, 'share_pct' => 5.0], 'moduloweb' => ['reservations' => 6, 'share_pct' => 3.0], 'sem canal' => ['reservations' => 4, 'share_pct' => 2.0],
        ], 'total' => 200]);
        $this->fresh();

        $p = $this->payload($this->baixa->id);
        $this->assertSame('Esta semana em Yuko', $p['top']['title']);
        $this->assertSame(['store_variation', 'revenue', 'family', 'same_day'], array_column($p['top']['numbers'], 'kind'));
        $this->assertSame(-31.0, $p['top']['numbers'][0]['value']); // 290 contra 200 unidades vendidas a 10 € cada
        $this->assertSame(75.0, $p['top']['numbers'][3]['value']);
        $this->assertCount(1, $p['top']['notes']);
        $this->assertStringContainsString('desceram 31% face às 4 anteriores, e a maior parte dos artigos desceu com a loja', $p['top']['notes'][0]);
        $this->assertSame(['Artigo A'], array_column($p['blocks']['changes']['down'], 'name'), 'Os artigos em descida continuam a aparecer.');
        $this->assertSame('Pratos principais', $p['blocks']['changes']['down'][0]['category']);
        $this->assertSame(5, $p['blocks']['changes']['visible']);
        $this->assertSame(['Sem reserva', 'Plataformas externas', 'Registadas pela equipa', 'App', 'Site', 'Sem canal indicado'],
            array_column($p['blocks']['channels']['stores'][0]['channels'], 'label'));
        $this->assertSame(75.0, $p['blocks']['decide']['same_day_pct']);
    }

    // ── "Onde", exclusão permanente e o resumo do dashboard ──────────────────

    public function test_where_is_always_instagram_and_facebook_and_says_if_the_networks_are_connected(): void
    {
        $this->weak($this->baixa, 3, 'almoco', 'alta', 30);
        $this->fresh();

        $where = $this->payload(null, true)['plays'][0]['where'];
        $this->assertSame(['instagram', 'facebook'], array_column($where['networks'], 'network'));
        $this->assertSame(['ig_feed_image', 'fb_photos'], array_column($where['networks'], 'format_key'));
        $this->assertFalse($where['connected']);
        $this->assertArrayNotHasKey('note', $where);

        // Com uma regra de formato e as redes ligadas.
        \App\Models\SocialFollowerSnapshot::create(['company_id' => $this->company->id, 'platform' => 'instagram', 'snapshot_date' => '2026-10-07', 'followers_count' => 4000, 'source' => 'manual']);
        \App\Models\CreativeFormatRule::query()->delete();
        \App\Models\CreativeFormatRule::create(['channel' => 'instagram', 'followers_min' => 0, 'followers_max' => null, 'format_key' => 'ig_carousel', 'rank' => 1,
            'source_label' => 'Estudo de teste', 'is_active' => true]);
        $conn = \App\Models\SocialConnection::create(['company_id' => $this->company->id, 'status' => 'active', 'access_token' => 'x', 'connected_at' => now()]);
        \App\Models\SocialConnectionAccount::create(['company_id' => $this->company->id, 'social_connection_id' => $conn->id, 'platform' => 'facebook', 'external_id' => '1', 'page_id' => '1', 'name' => 'Yuko', 'page_access_token' => 'p']);
        $where = $this->payload(null, true)['plays'][0]['where'];
        $this->assertTrue($where['connected']);
        $this->assertSame(['instagram', 'facebook'], array_column($where['networks'], 'network'));
        $this->assertSame('ig_carousel', $where['networks'][0]['format_key']);
    }

    public function test_an_item_excluded_for_good_leaves_plays_and_rankings_and_can_be_included_again(): void
    {
        $this->sales($this->baixa, 'C1', 'Couvert', 'PAO', 100, 160, 300);
        $this->sales($this->baixa, 'F1', 'Francesinha', 'FRANC', 200, 200, 1200);
        $this->confirm('PAO', 'petiscos'); // a família continua "Petiscos": não se mexe na categoria
        $this->confirm('FRANC', 'pratos');
        $couvert = $this->item($this->baixa, 'item_up', 'C1', 'Couvert', 'alta', 100, 160);
        $this->fresh();
        $this->assertSame('Dar palco a Couvert', $this->payload(null, true)['plays'][0]['title']);

        $base = "/api/v1/companies/{$this->company->id}/integrations/pingwin";
        Queue::fake();
        $this->actingAs($this->admin, 'sanctum')->postJson("{$base}/signals/exclude-item", ['key' => $couvert->signal_key])->assertOk()
            ->assertJsonPath('data.item.name', 'Couvert');
        Queue::assertPushed(\App\Jobs\RecomputeRestaurantSignalsJob::class);
        $row = \App\Models\RestaurantExcludedItem::sole();
        $this->assertSame([$this->admin->id, 'C1'], [$row->excluded_by_user_id, $row->product_pingwin_id]);
        $this->assertNotNull($row->excluded_at);

        $p = $this->payload();
        $this->assertSame([], $p['plays']);
        $this->assertSame([], $p['blocks']['changes']['up']);
        $this->assertSame(['Francesinha'], array_column($p['blocks']['stars']['stores'][0]['items'], 'name'));
        $this->assertSame('petiscos', RestaurantFamilyCategory::where('family_pingwin_id', 'PAO')->value('category'));
        $list = $this->getJson("{$base}/family-categories")->assertOk()->json('data.excluded_items');
        $this->assertSame(['Couvert'], array_column($list, 'name'));

        // Voltar a incluir (com registo).
        $this->postJson("{$base}/excluded-items/{$row->id}/include")->assertOk()->assertJsonPath('data.excluded_items', []);
        $this->assertSame($this->admin->id, $row->fresh()->included_by_user_id);
        $this->assertNotNull($row->fresh()->included_at);
        $this->assertSame('Dar palco a Couvert', $this->payload(null, true)['plays'][0]['title']);
        $this->postJson("{$base}/excluded-items/{$row->id}/include")->assertStatus(422);
    }

    public function test_the_dashboard_gets_only_the_plays_without_the_sales_top(): void
    {
        $this->weak($this->baixa, 3, 'almoco', 'alta', 30);
        $this->fresh();
        $data = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/marketing/bussola?plays_only=1")->assertOk()->json('data');
        $this->assertNull($data['top']);
        $this->assertArrayNotHasKey('blocks', $data);
        $this->assertCount(1, $data['plays']);
        $this->assertSame(['Baixa', 'Costa'], array_column($data['locations'], 'name'));
    }

    // ── Criar publicação e sugerir texto ─────────────────────────────────────

    public function test_create_post_from_a_play_links_its_signals_and_never_takes_a_past_date(): void
    {
        $a = $this->weak($this->baixa, 3, 'almoco', 'alta', 30);
        $b = $this->weak($this->costa, 2, 'almoco', 'media', 25);
        $this->fresh();
        EditorialMonth::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'state' => EditorialMonth::OPEN]);
        $url = "/api/v1/companies/{$this->company->id}/marketing/bussola";
        $page = $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()->json('data');
        $this->assertTrue($page['can_act']);
        $play = $page['plays'][0];

        $body = ['play_key' => $play['key'], 'title' => $play['what']['text'], 'publish_date' => '2026-10-07', 'networks' => ['instagram'],
            'media_formats' => ['instagram' => 'ig_carousel'], 'format' => 'Imagem única', 'caption' => 'Esta quarta, almoço na Baixa.', 'hashtags' => ['#yuko']];
        $this->postJson("{$url}/post", $body)->assertStatus(422)->assertJsonValidationErrors('publish_date');
        $post = $this->postJson("{$url}/post", ['publish_date' => '2026-10-12'] + $body)->assertOk()->json('data.post');

        $p = EditorialPost::with('networks')->findOrFail($post['id']);
        $this->assertSame(['idea', 'ig_carousel'], [$p->stage, $p->networks->first()->media_format]);
        $this->assertSame('Esta quarta, almoço na Baixa.', DB::table('editorial_post_versions')->where('id', $p->current_version_id)->value('caption'));
        $this->assertEqualsCanonicalizing([$a->signal_key, $b->signal_key],
            RestaurantSignalAction::where('editorial_post_id', $p->id)->pluck('signal_key')->all());
        $this->postJson("{$url}/post", ['play_key' => 'weak_period:wd9'] + $body + ['publish_date' => '2026-10-12'])->assertStatus(422);
        // A regra vale também para as sugestões antigas.
        $this->postJson("/api/v1/companies/{$this->company->id}/integrations/pingwin/signals/post",
            ['key' => $a->signal_key, 'title' => 'X', 'publish_date' => '2026-10-01', 'networks' => ['instagram'], 'format' => 'Imagem única'])->assertStatus(422);
    }

    public function test_suggest_text_generates_captions_without_creating_a_post_and_counts_for_the_limit(): void
    {
        Queue::fake();
        $this->weak($this->baixa, 3, 'almoco', 'alta', 30);
        $this->fresh();
        $url = "/api/v1/companies/{$this->company->id}/marketing/bussola";
        $play = $this->actingAs($this->admin, 'sanctum')->getJson($url . '?summary=1')->assertOk()->json('data.plays.0');

        $r = $this->postJson("{$url}/caption", ['play_key' => $play['key'], 'networks' => ['instagram']])->assertStatus(202)->json('data');
        $req = AiRequest::findOrFail($r['id']);
        $this->assertSame(['caption', null, 'bussola'], [$req->mode, $req->editorial_post_id, $req->variant]);
        $this->assertSame($play['what']['text'], $req->input['draft']['brief']);
        $this->assertSame(0, EditorialPost::count());
        $this->assertSame(1, $r['used']);

        // A IA simulada recebe a jogada como contexto (sem publicação nem imagens).
        $this->configureAi();
        $this->fakeAi(['proposals' => [['angle' => 'Convite', 'captions' => ['instagram' => 'Venha almoçar à quarta.'], 'hashtags' => ['#yuko'], 'cta' => 'Reserve já.']]]);
        app(\App\Services\Editorial\CaptionAiService::class)->process($req->id);
        $done = $this->getJson("{$url}/caption/{$req->id}")->assertOk()->json('data');
        $this->assertSame('done', $done['status']);
        $this->assertSame('Venha almoçar à quarta.', $done['result']['proposals'][0]['captions']['instagram']);
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'][0]['text'] ?? '', 'O que publicar: ' . $play['what']['text']));
    }

    // ── Sem Finanças (complemento ao pré-deploy, ponto 2) ───────────────────

    public function test_without_finance_the_compass_comes_without_any_euro_value(): void
    {
        $this->sales($this->baixa, 'S1', 'Cheesecake', 'DOCES', 100, 40);
        $this->sales($this->baixa, 'S2', 'Mousse', 'DOCES', 80, 30);
        $this->confirm('DOCES', 'sobremesas');
        $this->signal($this->baixa, 'weak_period:1:3:almoco', 'weak_period', 'alta',
            ['weekday' => 3, 'shift' => 'almoco', 'mode' => 'hours', 'avg_cents' => 45000, 'mean_cents' => 90000, 'pct_below' => 50, 'offset_days' => 1],
            'O almoço de quarta ficou 50% abaixo da média (média de 450 € contra 900 €, em 8 quartas).');
        $this->item($this->baixa, 'item_down', 'S1', 'Cheesecake', 'alta', 100, 40, -10);
        $this->fresh();
        $profile = \App\Models\PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Marketing sem Finanças',
            'is_system' => false, 'is_suggestion' => false]);
        $profile->syncPermissions(['bussola.ver', 'editorial.ver']);
        $marketing = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $profile->id]);
        $url = "/api/v1/companies/{$this->company->id}/marketing/bussola?location_id={$this->baixa->id}";

        $page = $this->actingAs($marketing, 'sanctum')->getJson($url)->assertOk()->json('data');
        $json = json_encode($page, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('€', $json, 'nenhum valor em euros');
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]+_cents":(?!null)/', $json, 'nenhum campo em cêntimos com valor');
        $this->assertSame(['visible' => false, 'note' => 'Sem acesso aos valores financeiros'], $page['financial']);
        $revenue = collect($page['top']['numbers'])->firstWhere('kind', 'revenue');
        $this->assertSame([null, true], [$revenue['value'], $revenue['hidden']]);
        // Visíveis: as jogadas, as quantidades, os mais vendidos e as variações em percentagem.
        $this->assertNotEmpty($page['plays']);
        $this->assertSame('Cheesecake', $page['blocks']['stars']['stores'][0]['items'][0]['name']);
        $this->assertNotNull($page['blocks']['stars']['stores'][0]['items'][0]['qty']);
        $this->assertNotNull(collect($page['top']['numbers'])->firstWhere('kind', 'store_variation')['value']);
        $weak = collect($page['plays'])->firstWhere('type', 'weak_period');
        $this->assertSame([null, true, 50], [$weak['bars'][0]['value'], $weak['bars'][0]['hidden'], $weak['bars'][0]['pct']]);
        $this->assertStringContainsString('50% abaixo da média', implode(' ', $weak['detail']['sentences']));

        // O separador Marketing do dashboard (só as jogadas) e o painel dos sinais seguem a mesma regra.
        $this->assertStringNotContainsString('€', json_encode($this->actingAs($marketing, 'sanctum')->getJson($url . '&plays_only=1')->assertOk()->json('data'), JSON_UNESCAPED_UNICODE));
        $signals = json_encode($this->actingAs($marketing, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/pingwin/signals")->assertOk()->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('€', $signals);
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]+_cents":(?!null)/', $signals);

        // O mapa da semana dentro da Bússola: rota própria (bussola.ver), em intensidade, sem euros;
        // a rota da restauração continua a exigir restauracao.ver.
        $map = $this->actingAs($marketing, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/marketing/bussola/heatmap?weeks=8")->assertOk()->json('data');
        $this->assertSame(false, $map['financial']['visible']);
        if (is_array($map['sales'])) {
            $this->assertSame('relative', $map['sales']['unit']);
        }
        $this->actingAs($marketing, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/pingwin/heatmap")->assertForbidden();
        $this->assertArrayNotHasKey('financial', $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/marketing/bussola/heatmap")->assertOk()->json('data'));

        // Com Finanças, os valores vêm.
        $admin = $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()->json('data');
        $this->assertTrue($admin['financial']['visible']);
        $this->assertGreaterThan(0, collect($admin['top']['numbers'])->firstWhere('kind', 'revenue')['value']);
    }
}
