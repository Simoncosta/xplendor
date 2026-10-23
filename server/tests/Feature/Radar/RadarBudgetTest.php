<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Models\AiRequest;
use App\Models\Alert;
use App\Models\Company;
use App\Models\ContentSector;
use App\Models\RadarItem;
use App\Models\RadarRelevance;
use App\Models\RadarSignal;
use App\Models\RadarTopic;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\CompanyModuleService;
use App\Services\Radar\Fit\RadarFitService;
use App\Services\Radar\RadarBudget;
use App\Services\Radar\Relevance\RadarRelevanceService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAi;
use Tests\TestCase;

/**
 * Bússola 1: o teto de custo da IA do Radar. 2 USD por dia no global (relevância e "porque" somados)
 * e 0,30 USD por empresa e por dia (o "porque"), no dia civil de Lisboa. Ao bater no teto: a relevância
 * fica para o dia seguinte, o "porque" usa a frase de recurso, e o root recebe um aviso no sino uma vez
 * por dia. A relevância fica também em ai_requests (sem empresa); a repetição do verificador de
 * português conta para o custo. IA simulada.
 */
class RadarBudgetTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;

    // 10:00 UTC = 11:00 em Lisboa (hora de verão): o dia de Lisboa começa às 23:00 UTC de 13/10.
    private const NOW = '2026-10-14 10:00:00';

    private Company $company;
    private Company $other;
    private Company $team;
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));
        Http::preventStrayRequests();
        config(['radar.enabled' => true]);
        $this->planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->team = $this->makeCompany('500019100', 'Equipa', null, []);
        $this->company = $this->makeCompany('500019101', 'Tasca Exemplo', 'restauracao');
        $this->other = $this->makeCompany('500019102', 'Outra Tasca', 'restauracao');
        User::factory()->create(['company_id' => $this->team->id, 'role' => 'root']);
        \App\Access\CompatibilityMigration::run();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeCompany(string $nipc, string $name, ?string $sector, array $modules = ['linha_editorial', 'radar']): Company
    {
        $c = Company::create(['nipc' => $nipc, 'fiscal_name' => "{$name} Lda", 'trade_name' => $name, 'plan_id' => $this->planId, 'subscription_status' => 'active',
            'content_sector_id' => $sector ? ContentSector::where('slug', $sector)->value('id') : null]);
        foreach ($modules as $m) {
            app(CompanyModuleService::class)->enable($c->id, $m);
        }

        return $c;
    }

    private function topic(string $title): RadarTopic
    {
        return RadarTopic::create([
            'kind' => 'trend', 'title' => $title, 'normalized_key' => 'trend:' . md5($title), 'first_seen_at' => now()->subHours(2), 'last_seen_at' => now()->subHour(),
            'approx_volume' => 500, 'approx_volume_label' => '500+', 'filter_verdict' => RadarItem::VERDICT_PASSED, 'filter_reasons' => [], 'filter_flags' => [],
            'sources' => [['title' => $title, 'source_name' => 'Google Trends', 'url' => 'https://exemplo.pt/noticia', 'published_at' => null]],
            'window_until' => now()->addHours(46), 'expires_at' => now()->addDays(30),
        ]);
    }

    private function relevant(RadarTopic $t): RadarRelevance
    {
        return RadarRelevance::create(['topic_id' => $t->id, 'sector_slug' => 'restauracao', 'relevant' => true, 'score' => 70, 'theme' => 'gastronomia']);
    }

    /** Um gasto já feito, à hora indicada (UTC). */
    private function spent(float $usd, ?int $companyId, string $mode = AiRequest::MODE_RADAR_PORQUE, string $at = self::NOW): void
    {
        AiRequest::forceCreate(['company_id' => $companyId, 'mode' => $mode, 'status' => AiRequest::DONE, 'input' => [], 'model' => 'claude-haiku-5-5', 'prompt_version' => "{$mode}-v1", 'cost_usd' => $usd,
            'created_at' => $at, 'updated_at' => $at]);
    }

    private function relevanceAnswers(): void
    {
        Http::fake(['api.anthropic.com/*' => function ($req) {
            preg_match_all('/\\\\"id\\\\":(\d+)/', (string) $req->body(), $m);

            return $this->anthropicResponse(['avaliacoes' => array_map(fn ($id) => ['id' => (int) $id, 'relevante' => true, 'pontuacao' => 80, 'tema' => 'gastronomia', 'frase' => 'Feira gastronómica.'], $m[1])]);
        }]);
    }

    private function alerts(): int
    {
        return Alert::where('company_id', $this->team->id)->where('title', 'like', 'Radar: teto%')->count();
    }

    public function test_the_relevance_is_recorded_in_ai_requests_without_a_company_and_counts_for_the_day(): void
    {
        $this->configureAi();
        $this->topic('Feira do queijo');
        $this->relevanceAnswers();

        $this->assertSame(1, app(RadarRelevanceService::class)->evaluate()['pedidos']);
        $row = AiRequest::where('mode', AiRequest::MODE_RADAR_RELEVANCIA)->sole();
        $this->assertNull($row->company_id);
        $this->assertSame('claude-haiku-5-5', $row->model);
        $this->assertGreaterThan(0, $row->cost_usd);
        $this->assertEqualsWithDelta((float) RadarRelevance::sum('cost_usd'), $row->cost_usd, 0.000001);
        $this->assertEqualsWithDelta($row->cost_usd, RadarBudget::spentToday(), 0.000001);
        $this->assertSame(0.0, RadarBudget::spentToday($this->company->id), 'a relevância não é de nenhuma empresa');
    }

    public function test_with_the_global_cap_reached_the_relevance_waits_and_the_root_is_warned_once_a_day(): void
    {
        $this->configureAi();
        $this->topic('Feira do queijo');
        $this->spent(2.00, $this->company->id);

        $out = app(RadarRelevanceService::class)->evaluate();
        $this->assertSame(0, $out['pedidos']);
        $this->assertSame('Teto diário de custo do Radar atingido.', $out['motivo']);
        $this->assertSame(0, RadarRelevance::count(), 'fica para o dia seguinte');
        Http::assertNothingSent();
        $this->assertSame(1, $this->alerts());

        app(RadarRelevanceService::class)->evaluate();
        $this->assertSame(1, $this->alerts(), 'um aviso por dia');

        // No dia seguinte (Lisboa), o gasto recomeça do zero.
        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 09:00:00'));
        $this->relevanceAnswers();
        $this->assertSame(1, app(RadarRelevanceService::class)->evaluate()['pedidos']);
    }

    public function test_the_day_is_the_lisbon_civil_day(): void
    {
        $this->spent(1.00, null, AiRequest::MODE_RADAR_RELEVANCIA, '2026-10-13 22:59:00'); // 23:59 de 13/10 em Lisboa
        $this->spent(0.40, null, AiRequest::MODE_RADAR_RELEVANCIA, '2026-10-13 23:00:00'); // 00:00 de 14/10 em Lisboa
        $this->spent(9.00, $this->company->id, AiRequest::MODE_CAPTION); // outra função não conta
        $this->assertEqualsWithDelta(0.40, RadarBudget::spentToday(), 0.000001);
    }

    public function test_with_the_company_cap_reached_its_why_uses_the_template_and_the_other_company_still_gets_the_ai(): void
    {
        $this->configureAi();
        $this->relevant($this->topic('Feira do queijo da serra'));
        $this->spent(0.30, $this->company->id);
        Http::fake(['api.anthropic.com/*' => $this->anthropicResponse('Uma feira gastronómica próxima do que a marca comunica sobre produtos da região.')]);

        app(RadarFitService::class)->fit($this->company->id);
        app(RadarFitService::class)->fit($this->other->id);

        $this->assertSame('template', RadarSignal::where('company_id', $this->company->id)->value('why_source'));
        $this->assertSame('ai', RadarSignal::where('company_id', $this->other->id)->value('why_source'));
        Http::assertSentCount(1);
        $this->assertSame(1, $this->alerts());
        $this->assertStringContainsString("empresa {$this->company->id}", Alert::where('company_id', $this->team->id)->value('title'));
    }

    public function test_with_the_global_cap_reached_every_why_uses_the_template(): void
    {
        $this->configureAi();
        $this->relevant($this->topic('Feira do queijo da serra'));
        $this->spent(1.95, null, AiRequest::MODE_RADAR_RELEVANCIA);
        $this->spent(0.05, $this->company->id);

        app(RadarFitService::class)->fit($this->other->id);
        $this->assertSame('template', RadarSignal::where('company_id', $this->other->id)->value('why_source'));
        Http::assertNothingSent();
    }

    public function test_the_portuguese_retry_counts_for_the_cap(): void
    {
        $this->configureAi();
        $this->relevant($this->topic('Feira do queijo da serra'));
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->pushResponse($this->anthropicResponse('Uma feira que você pode aproveitar para mostrar os produtos da região.'))
            ->pushResponse($this->anthropicResponse('Uma feira gastronómica próxima do que a marca comunica sobre produtos da região.'))]);

        app(RadarFitService::class)->fit($this->company->id);
        $row = AiRequest::where('mode', AiRequest::MODE_RADAR_PORQUE)->sole();
        $this->assertTrue($row->pt_retried);
        $usage = ['input' => 1000, 'output' => 500];
        $this->assertEqualsWithDelta(AiGateway::cost('claude-opus-5-5', [$usage, $usage]), $row->cost_usd, 0.000001);
        $this->assertEqualsWithDelta($row->cost_usd, RadarBudget::spentToday($this->company->id), 0.000001);
    }

    public function test_the_root_panel_shows_the_spending_of_the_day(): void
    {
        $this->spent(0.12, null, AiRequest::MODE_RADAR_RELEVANCIA);
        $this->spent(0.30, $this->company->id);
        $this->spent(0.05, $this->other->id);
        $this->spent(3.00, $this->company->id, AiRequest::MODE_RADAR_PORQUE, '2026-10-12 10:00:00');
        $root = User::where('role', 'root')->first();

        $budget = $this->actingAs($root, 'sanctum')->getJson('/api/v1/admin/radar')->assertOk()->json('data.budget');
        $this->assertSame(2, (int) $budget['global_cap_usd']);
        $this->assertEqualsWithDelta(0.30, $budget['company_cap_usd'], 0.0001);
        $this->assertEqualsWithDelta(0.47, $budget['spent_today_usd'], 0.0001);
        $this->assertFalse($budget['global_reached']);
        $this->assertEqualsWithDelta(0.12, collect($budget['by_function'])->firstWhere('function', 'radar_relevancia')['cost_usd'], 0.0001);
        $this->assertSame([$this->company->id, $this->other->id], array_column($budget['companies'], 'company_id'));
        $this->assertTrue($budget['companies'][0]['reached']);
        $this->assertFalse($budget['companies'][1]['reached']);
    }
}
