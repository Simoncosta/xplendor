<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Models\Company;
use App\Models\RadarItem;
use App\Models\User;
use App\Services\Radar\RadarCollector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Radar (R1): o painel do root (Administração › Radar). Só o root; o resumo (a última recolha de
 * cada fonte, com a atribuição e os termos; o que entrou; o que o filtro tirou e porquê; as
 * tendências) e a lista dos itens; nenhum dado pessoal.
 */
class RadarAdminTest extends TestCase
{
    use FakesRadarFeeds;
    use RefreshDatabase;

    private User $root;
    private User $admin;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['radar.enabled' => true]); // RADAR_ENABLED (desligado por omissão)
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 18:20:00'));
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $xplendor = Company::create(['nipc' => '500900002', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $client = Company::create(['nipc' => '500900001', 'fiscal_name' => 'Cliente Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $xplendor->id, 'role' => 'root']);
        $this->admin = User::factory()->create(['company_id' => $client->id, 'role' => 'admin']);
        $this->user = User::factory()->create(['company_id' => $client->id, 'role' => 'user']);
    }

    private function collectSamples(): void
    {
        $collector = app(RadarCollector::class);
        foreach (['google_trends' => 'google-trends/trending-rss-pt.xml', 'jornal_eco' => 'jornais/eco.xml', 'jornal_publico' => 'jornais/publico.xml',
            'jornal_observador' => 'jornais/observador.xml', 'bce_press' => 'bce/press.xml'] as $key => $file) {
            $collector->collect($key, true, ['fixture' => $this->fixturePath($file)]);
        }
    }

    public function test_only_the_root_sees_the_radar_panel(): void
    {
        $urls = ['/api/v1/admin/radar', '/api/v1/admin/radar/items'];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach ($urls as $url) {
            $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertForbidden();
            $this->actingAs($this->user, 'sanctum')->getJson($url)->assertForbidden();
            $this->actingAs($this->root, 'sanctum')->getJson($url)->assertOk();
        }
    }

    public function test_the_summary_shows_sources_totals_reasons_and_topics(): void
    {
        $this->robotsAlreadyRead();
        $this->fakeFeeds(['eco.sapo.pt/feed/*' => Http::response('Serviço indisponível', 503)]);
        app(RadarCollector::class)->collect(null, true);

        $data = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar')->assertOk()->json('data');

        $sources = collect($data['sources'])->keyBy('key');
        $this->assertSame(['google_trends', 'jornal_eco', 'jornal_publico', 'jornal_observador', 'bce_press', 'ipma_avisos'], $sources->keys()->all());
        $this->assertSame('Fonte: Google Trends', $sources['google_trends']['attribution']);
        $this->assertSame('https://policies.google.com/terms?hl=pt-PT', $sources['google_trends']['terms_url']);
        $this->assertSame(['ok', 10, 1], [$sources['google_trends']['last_run']['status'], $sources['google_trends']['last_run']['items_new'], $sources['google_trends']['requests_last_hour']]);
        $this->assertSame(['erro', 'A fonte respondeu HTTP 503.', 503], [$sources['jornal_eco']['last_run']['status'], $sources['jornal_eco']['last_run']['error'], $sources['jornal_eco']['last_run']['http_status']]);
        $this->assertFalse($sources['ipma_avisos']['enabled']);
        $this->assertStringContainsString('confirmação escrita do IPMA', $sources['ipma_avisos']['disabled_reason']);
        $this->assertNull($sources['ipma_avisos']['last_run']);

        // 10 (Trends) + 10 (Público) + 51 (Observador) + 1 (BCE).
        $this->assertSame(72, $data['totals']['items_24h']);
        $this->assertSame(RadarItem::where('filter_verdict', 'blocked')->count(), $data['totals']['blocked_24h']);
        $reasons = collect($data['blocked_by_reason'])->keyBy('reason');
        $this->assertSame('Política', $reasons['politica']['label']);
        $this->assertGreaterThan(0, $reasons['politica']['count']);
        $this->assertSame(['tragedia', 'morte', 'acidente', 'crime', 'doenca', 'politica', 'religiao', 'conflito', 'vida_pessoal'], $reasons->keys()->all());
        $this->assertNotEmpty($data['topics']);
        $this->assertSame(['id', 'kind', 'title', 'first_seen_at', 'last_seen_at', 'window_until', 'open', 'approx_volume', 'approx_volume_label', 'districts',
            'verdict', 'reasons', 'flags', 'items_count', 'source_names'], array_keys($data['topics'][0]));
        $this->assertSame(['max_requests_per_hour' => 4, 'retention_days' => 30], $data['limits']);
    }

    public function test_the_items_list_filters_by_verdict_source_and_days_and_paginates(): void
    {
        $this->collectSamples();

        $all = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar/items?per_page=10')->assertOk()->json('data');
        $this->assertSame(94, $all['pagination']['total']);
        $this->assertCount(10, $all['items']);
        $this->assertSame(10, $all['pagination']['last_page']);

        $blocked = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar/items?verdict=blocked&per_page=100')->json('data');
        $this->assertSame(RadarItem::where('filter_verdict', 'blocked')->count(), $blocked['pagination']['total']);
        $this->assertTrue(collect($blocked['items'])->every(fn ($i) => $i['verdict'] === 'blocked' && $i['reasons'] !== []));

        $trends = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar/items?source=google_trends&per_page=100')->json('data.items');
        $this->assertCount(10, $trends);
        $navio = collect($trends)->firstWhere('title', 'navio');
        $this->assertSame([500, '500+', 'Google Trends', 'passed'], [$navio['approx_volume'], $navio['approx_volume_label'], $navio['source_name'], $navio['verdict']]);
        $this->assertSame('trend', $navio['topic']['kind']);
        $this->assertStringStartsWith('https://www.jm-madeira.pt/', $navio['url']);

        // Um título de jornal sem par fica "sem tendência".
        $alone = collect($this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar/items?source=jornal_publico&per_page=100')->json('data.items'))
            ->firstWhere('title', 'Portugal deposita mais 334 amostras de sementes no “cofre do apocalipse” na Noruega');
        $this->assertSame(['news', null], [$alone['source_type'], $alone['topic']]);

        // Os dias: daqui a 2 dias, a lista das últimas 24 horas fica vazia; a de 7 dias não.
        $this->travel(2)->days();
        $this->assertSame(0, $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar/items')->json('data.pagination.total'));
        $this->assertSame(94, $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar/items?days=7')->json('data.pagination.total'));

        $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar/items?verdict=talvez')->assertUnprocessable();
        $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar/items?source=inventada')->assertUnprocessable();
        $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar/items?days=31')->assertUnprocessable();
    }

    public function test_no_personal_data_in_the_panel(): void
    {
        $this->collectSamples();

        $items = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar/items?days=1&per_page=100')->assertOk()->json('data.items');
        $summary = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar')->getContent();

        // Só o título, a ligação, a fonte, a hora, o volume e o veredicto: nada do artigo nem do autor.
        $this->assertSame(['id', 'title', 'url', 'source_key', 'source_type', 'source_name', 'published_at', 'fetched_at', 'approx_volume',
            'approx_volume_label', 'verdict', 'reasons', 'flags', 'topic'], array_keys($items[0]));
        $dump = json_encode($items, JSON_UNESCAPED_UNICODE) . $summary;
        foreach (['CORPO-DO-ARTIGO', 'RESUMO-DO-ARTIGO', 'Joana Exemplo', 'creator', 'description', 'extra', 'news_item'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dump);
        }
    }
}
