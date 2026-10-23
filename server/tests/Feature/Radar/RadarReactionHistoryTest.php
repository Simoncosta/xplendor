<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Jobs\PurgeRadarItemsJob;
use App\Jobs\PurgeRadarReactionHistoryJob;
use App\Models\Company;
use App\Models\ContentSector;
use App\Models\EditorialMonth;
use App\Models\RadarItem;
use App\Models\RadarReactionHistory;
use App\Models\RadarRelevance;
use App\Models\RadarSignal;
use App\Models\RadarTopic;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Radar\RadarSignalActions;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Bússola 4: o histórico das reações ao Radar. Cada decisão (reagir, ignorar, não mostrar e o
 * "Reagir" a uma data do calendário) fica com o tema, o ramo, a empresa, a pontuação e a data, sem o
 * título nem o conteúdo dos artigos; a purga dos 30 dias não lhe toca; a própria apaga aos 12 meses.
 */
class RadarReactionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-14 10:00:00';

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500019501', 'fiscal_name' => 'Tasca Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active',
            'content_sector_id' => ContentSector::where('slug', 'restauracao')->value('id')]);
        foreach (['linha_editorial', 'radar'] as $m) {
            app(CompanyModuleService::class)->enable($this->company->id, $m);
        }
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        EditorialMonth::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'state' => EditorialMonth::OPEN]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function signal(string $title, int $score = 72): RadarSignal
    {
        $topic = RadarTopic::create(['kind' => 'trend', 'title' => $title, 'normalized_key' => 'trend:' . md5($title), 'first_seen_at' => now()->subHour(), 'last_seen_at' => now(),
            'filter_verdict' => RadarItem::VERDICT_PASSED, 'filter_reasons' => [], 'filter_flags' => [], 'sources' => [['title' => $title, 'source_name' => 'Jornal Exemplo', 'url' => 'https://exemplo.pt/a']],
            'window_until' => now()->addDay(), 'expires_at' => now()->addDays(30)]);
        RadarRelevance::create(['topic_id' => $topic->id, 'sector_slug' => 'restauracao', 'relevant' => true, 'score' => 70, 'theme' => 'gastronomia']);

        return RadarSignal::create(['company_id' => $this->company->id, 'topic_id' => $topic->id, 'why' => 'Uma feira gastronómica.', 'why_source' => 'template',
            'window_until' => now()->addDay(), 'status' => RadarSignal::NEW, 'score' => $score, 'flags' => [], 'sources' => []])->load('topic');
    }

    public function test_each_decision_is_recorded_with_theme_sector_score_and_date_without_titles(): void
    {
        $actions = app(RadarSignalActions::class);
        $reacted = $this->signal('Feira do queijo da serra', 85);
        $actions->react($this->company, $this->admin, $reacted, ['title' => 'Está a subir: Feira do queijo', 'publish_date' => '2026-10-20', 'networks' => ['instagram'], 'format' => 'Imagem única']);
        $actions->ignore($this->signal('Concurso de vinhos', 60), $this->admin);
        $actions->mute($this->signal('Mercado de outono', 55), $this->admin);
        $actions->reactToDate($this->company->load('contentSector'), $this->admin, 'bce.monetary_policy_decision', '2026-10-29',
            ['title' => 'Decisão do BCE', 'publish_date' => '2026-10-29', 'networks' => ['instagram'], 'format' => 'Imagem única']);

        $rows = RadarReactionHistory::orderBy('id')->get();
        $this->assertSame(['reacted', 'ignored', 'muted', 'reacted'], $rows->pluck('decision')->all());
        $this->assertSame(['trend', 'trend', 'trend', 'calendar'], $rows->pluck('topic_kind')->all());
        $this->assertSame([85, 60, 55, 0], $rows->pluck('score')->all());
        $this->assertSame(['gastronomia', 'gastronomia', 'gastronomia', 'bce.monetary_policy_decision'], $rows->pluck('theme')->all());
        $this->assertSame(['restauracao'], $rows->pluck('sector_slug')->unique()->values()->all());
        $this->assertSame($reacted->id, $rows[0]->signal_id);
        $this->assertSame(self::NOW, $rows[0]->decided_at->format('Y-m-d H:i:s'));

        $columns = Schema::getColumnListing('radar_reaction_history');
        foreach (['title', 'why', 'sources', 'url'] as $never) {
            $this->assertNotContains($never, $columns, 'sem o título nem o conteúdo dos artigos');
        }
    }

    public function test_the_30_day_purge_does_not_touch_the_history_and_its_own_purge_keeps_12_months(): void
    {
        app(RadarSignalActions::class)->ignore($this->signal('Feira do queijo'), $this->admin);
        $this->assertSame(1, RadarReactionHistory::count());

        // 31 dias depois: as tendências e os sinais vão-se; o histórico fica.
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00'));
        (new PurgeRadarItemsJob())->handle();
        $this->assertSame(0, RadarTopic::count());
        $this->assertSame(0, RadarSignal::count());
        $this->assertSame(1, RadarReactionHistory::count());

        // 11 meses e meio: ainda fica; 12 meses e um dia: apagada.
        Carbon::setTestNow(Carbon::parse('2027-09-30 10:00:00'));
        $this->assertSame(0, (new PurgeRadarReactionHistoryJob())->handle());
        Carbon::setTestNow(Carbon::parse('2027-10-15 10:00:00'));
        $this->assertSame(1, (new PurgeRadarReactionHistoryJob())->handle());
        $this->assertSame(0, RadarReactionHistory::count());
    }
}
