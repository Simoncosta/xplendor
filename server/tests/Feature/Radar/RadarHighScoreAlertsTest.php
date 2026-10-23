<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Mail\RadarHighScoreMail;
use App\Models\Alert;
use App\Models\Company;
use App\Models\ContentSector;
use App\Models\RadarItem;
use App\Models\RadarSignal;
use App\Models\RadarTopic;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\AlertService;
use App\Services\CompanyModuleService;
use App\Services\Radar\RadarHighScoreAlerts;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Bússola 7: os avisos dos sinais de nota alta. Sino e e-mail, no máximo um por dia e por empresa, só
 * das 9 às 20 horas (Lisboa), a partir do limiar do root (85 por omissão); o e-mail segue a guarda
 * ExternalCalls; cada pessoa pode desligar os seus (sino e e-mail).
 */
class RadarHighScoreAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-14 10:00:00'; // 11:00 em Lisboa

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at(self::NOW);
        Http::preventStrayRequests();
        Mail::fake();
        config(['scheduler.external_jobs' => true]);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500019601', 'fiscal_name' => 'Tasca Exemplo Lda', 'trade_name' => 'Tasca Exemplo', 'plan_id' => $plan,
            'subscription_status' => 'active', 'content_sector_id' => ContentSector::where('slug', 'restauracao')->value('id')]);
        foreach (['linha_editorial', 'radar'] as $m) {
            app(CompanyModuleService::class)->enable($this->company->id, $m);
        }
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'email' => 'admin@tasca-exemplo.invalid']);
        \App\Access\CompatibilityMigration::run();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc));
    }

    /** Um sinal: $relevance é a relevância da IA para o ramo (0 a 100); $fit, a pontuação do encaixe (por omissão, a mesma). */
    private function signal(string $title, int $relevance, ?int $fit = null, ?Company $c = null): RadarSignal
    {
        $topic = RadarTopic::create(['kind' => 'trend', 'title' => $title, 'normalized_key' => 'trend:' . md5($title), 'first_seen_at' => now()->subHour(), 'last_seen_at' => now(),
            'filter_verdict' => RadarItem::VERDICT_PASSED, 'filter_reasons' => [], 'filter_flags' => [], 'sources' => [['title' => $title, 'source_name' => 'Jornal Exemplo', 'url' => 'https://exemplo.pt/a']],
            'window_until' => now()->addDays(2), 'expires_at' => now()->addDays(30)]);
        \App\Models\RadarRelevance::create(['topic_id' => $topic->id, 'sector_slug' => 'restauracao', 'relevant' => true, 'score' => $relevance, 'theme' => 'gastronomia']);

        return RadarSignal::create(['company_id' => ($c ?? $this->company)->id, 'topic_id' => $topic->id, 'why' => "Uma feira próxima do ramo ({$title}).", 'why_source' => 'template',
            'window_until' => now()->addDays(2), 'status' => RadarSignal::NEW, 'score' => $fit ?? $relevance, 'flags' => [], 'sources' => []]);
    }

    private function alertNow(): array
    {
        return app(RadarHighScoreAlerts::class)->run();
    }

    public function test_one_alert_a_day_for_the_best_signal_in_the_bell_and_by_email(): void
    {
        $best = $this->signal('Feira do queijo', 90);
        $second = $this->signal('Festival do pão', 86);
        $this->signal('Concurso de vinhos', 70);

        $this->assertSame(['alerts' => 1, 'emails' => 1, 'motivo' => null], $this->alertNow());
        $alert = Alert::where('company_id', $this->company->id)->sole();
        $this->assertSame(['Radar: está a subir Feira do queijo', 'opportunity', UserNotificationPreference::RADAR_HIGH_SCORE, '/marketing/bussola'],
            [$alert->title, $alert->type, $alert->kind, $alert->detail_path]);
        $this->assertNotNull($best->fresh()->alerted_at);
        $this->assertNull($second->fresh()->alerted_at);
        Mail::assertQueued(RadarHighScoreMail::class, fn ($m) => $m->hasTo('admin@tasca-exemplo.invalid') && $m->topic === 'Feira do queijo');

        // No mesmo dia de Lisboa, nada mais.
        $this->at('2026-10-14 17:30:00');
        $this->assertSame(0, $this->alertNow()['alerts']);
        // No dia seguinte, o seguinte.
        $this->at('2026-10-15 09:10:00');
        $this->assertSame(1, $this->alertNow()['alerts']);
        $this->assertNotNull($second->fresh()->alerted_at);
        $this->assertSame(1, RadarHighScoreAlerts::sentToday(), 'os avisos de hoje (dia de Lisboa)');
        $this->artisan('radar:high-score-alerts')->assertSuccessful();
    }

    public function test_the_threshold_looks_at_the_ai_relevance_and_the_fit_score_picks_the_best(): void
    {
        // Relevância 70 com um encaixe enorme (zona e marca): não chega ao limiar de 85.
        $belowThreshold = $this->signal('Feira do queijo', 70, 120);
        $relevantA = $this->signal('Festival do pão', 88, 98);
        $relevantB = $this->signal('Mercado de Natal', 95, 95);

        $this->assertSame(1, $this->alertNow()['alerts']);
        $this->assertNotNull($relevantA->fresh()->alerted_at, 'entre os relevantes, o de maior encaixe');
        $this->assertNull($relevantB->fresh()->alerted_at);
        $this->assertSame('Radar: está a subir Festival do pão', Alert::where('company_id', $this->company->id)->value('title'));

        // Nos dias seguintes: o outro relevante; o de relevância 70 nunca.
        $this->at('2026-10-15 10:00:00');
        $this->alertNow();
        $this->assertNotNull($relevantB->fresh()->alerted_at);
        $this->at('2026-10-16 10:00:00');
        $this->assertSame(0, $this->alertNow()['alerts']);
        $this->assertNull($belowThreshold->fresh()->alerted_at);
    }

    public function test_the_window_the_threshold_and_the_external_guard(): void
    {
        $s = $this->signal('Feira do queijo', 80);
        $this->at('2026-10-14 20:30:00'); // 21:30 em Lisboa
        $this->assertSame('Fora do horário dos avisos (9 às 20 horas, Lisboa).', $this->alertNow()['motivo']);
        $this->at('2026-10-14 07:59:00'); // 08:59 em Lisboa
        $this->assertNotNull($this->alertNow()['motivo']);

        $this->at(self::NOW);
        $this->assertSame(0, $this->alertNow()['alerts'], 'abaixo de 85');

        // O root baixa o limiar em Admin › Radar.
        $root = User::factory()->create(['company_id' => $this->company->id, 'role' => 'root']);
        $this->actingAs($root, 'sanctum')->putJson('/api/v1/admin/radar/alerts', ['min_score' => 101])->assertStatus(422)
            ->assertJsonPath('errors.min_score.0', 'Indique uma relevância inteira entre 0 e 100.');
        $this->actingAs($root, 'sanctum')->putJson('/api/v1/admin/radar/alerts', ['min_score' => 75])->assertOk()->assertJsonPath('data.min_score', 75);
        $this->actingAs($root, 'sanctum')->getJson('/api/v1/admin/radar')->assertOk()->assertJsonPath('data.alerts.min_score', 75)->assertJsonPath('data.alerts.default_min_score', 85);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/radar/alerts', ['min_score' => 10])->assertStatus(403);

        // Sem as chamadas externas: o sino sim, o e-mail não.
        config(['scheduler.external_jobs' => false]);
        $this->assertSame(['alerts' => 1, 'emails' => 0, 'motivo' => null], $this->alertNow());
        $this->assertNotNull($s->fresh()->alerted_at);
        Mail::assertNothingQueued();

        // A página do PingWin continua só com as definições do PingWin.
        $platform = app(\App\Http\Controllers\Api\V1\Admin\PingwinSettingsController::class)->index()->getData(true)['data']['platform'];
        $this->assertNotContains('radar.alert_min_score', array_column($platform, 'key'));
    }

    public function test_each_person_can_turn_these_alerts_off_in_the_bell_and_by_email(): void
    {
        $other = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'email' => 'outra@tasca-exemplo.invalid']);
        \App\Access\CompatibilityMigration::run();
        $base = "/api/v1/companies/{$this->company->id}/alerts";
        $this->actingAs($other, 'sanctum')->getJson("{$base}/preferences")->assertOk()->assertJsonPath('data.radar_high_score', true);
        $this->actingAs($other, 'sanctum')->putJson("{$base}/preferences", ['radar_high_score' => 'talvez'])->assertStatus(422);
        $this->actingAs($other, 'sanctum')->putJson("{$base}/preferences", ['radar_high_score' => false])->assertOk()->assertJsonPath('data.radar_high_score', false);

        app(AlertService::class)->createSystemAlert($this->company->id, 'warning', 'Outro aviso', 'Sem tipo.');
        $this->signal('Feira do queijo', 95);
        $this->assertSame(1, $this->alertNow()['emails']);
        Mail::assertQueued(RadarHighScoreMail::class, fn ($m) => $m->hasTo('admin@tasca-exemplo.invalid'));
        Mail::assertNotQueued(RadarHighScoreMail::class, fn ($m) => $m->hasTo('outra@tasca-exemplo.invalid'));

        $this->assertSame(['Outro aviso'], array_column($this->actingAs($other, 'sanctum')->getJson($base)->assertOk()->json('data'), 'title'));
        $this->actingAs($other, 'sanctum')->getJson("{$base}/unread-count")->assertOk()->assertJsonPath('data.count', 1);
        $this->actingAs($this->admin, 'sanctum')->getJson("{$base}/unread-count")->assertOk()->assertJsonPath('data.count', 2);
        // Marcar tudo como lido de quem desligou não marca o aviso que não vê.
        $this->actingAs($other, 'sanctum')->patchJson("{$base}/read")->assertOk()->assertJsonPath('data.updated', 1);
        $this->actingAs($this->admin, 'sanctum')->getJson("{$base}/unread-count")->assertJsonPath('data.count', 1);
        // O resumo diário por e-mail não repete estes avisos.
        $this->assertSame(['Outro aviso'], app(AlertService::class)->getAlertsForDailySummary($this->company)->pluck('title')->all());

        // A preferência é de quem está ligado: outra empresa não entra.
        $plan = (int) $this->company->plan_id;
        $foreign = Company::create(['nipc' => '500019602', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $intruder = User::factory()->create(['company_id' => $foreign->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $this->actingAs($intruder, 'sanctum')->getJson("{$base}/preferences")->assertStatus(403);
        $this->actingAs($intruder, 'sanctum')->putJson("{$base}/preferences", ['radar_high_score' => true])->assertStatus(403);
    }

    public function test_without_the_radar_module_or_bussola_permission_nobody_is_alerted_or_emailed(): void
    {
        $viewer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'email' => 'sem-bussola@tasca-exemplo.invalid']);
        $profile = \App\Models\PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Sem Bússola', 'is_system' => false, 'is_suggestion' => false]);
        $profile->syncPermissions(['editorial.ver']);
        $viewer->forceFill(['profile_id' => $profile->id])->save();
        $this->assertSame(['admin@tasca-exemplo.invalid'], array_map(fn ($u) => $u->email, app(RadarHighScoreAlerts::class)->recipients($this->company)));

        app(CompanyModuleService::class)->disable($this->company->id, 'radar');
        $this->signal('Feira do queijo', 99);
        $this->assertSame(0, $this->alertNow()['alerts']);
    }
}
