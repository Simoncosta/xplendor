<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WorkerHeartbeatJob;
use App\Mail\AgencyNoticeMail;
use App\Models\Alert;
use App\Models\Company;
use App\Models\User;
use App\Services\Health\HealthProbes;
use App\Services\Health\HealthWatch;
use App\Services\Health\SystemHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Monitorização: a rota pública /api/health (200 ou 503, sem detalhes), os sinais de vida do
 * scheduler e do worker, a consola do root e o health:watch (aviso uma vez ao fim de 10 minutos,
 * outra vez quando volta; email só com a guarda ExternalCalls ligada). As sondas são simuladas.
 */
class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private FakeHealthProbes $probes;

    private Company $team;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->probes = new FakeHealthProbes();
        $this->app->instance(HealthProbes::class, $this->probes);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->team = Company::create(['nipc' => '500019441', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $this->team->id, 'role' => 'root', 'email' => 'root@xplendor.invalid']);
        config(['health.alert_emails' => 'Alerta@Exemplo.invalid', 'quotes.team_company_id' => $this->team->id]);
    }

    private function beats(): void
    {
        SystemHealth::beat('scheduler');
        SystemHealth::beat('worker');
    }

    private function watch(): array
    {
        $this->beats();

        return app(HealthWatch::class)->watch();
    }

    public function test_the_public_route_answers_200_or_503_without_details(): void
    {
        $this->beats();
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok'])->assertHeader('Cache-Control', 'no-store, private');

        $this->probes->redis = 'O Redis não responde (fila e cache).';
        $response = $this->getJson('/api/health')->assertStatus(503)->assertExactJson(['status' => 'falha']);
        $this->assertStringNotContainsString('Redis', $response->getContent());
    }

    public function test_heartbeats_go_stale_and_a_worker_that_never_ran_is_a_failure(): void
    {
        SystemHealth::beat('scheduler');
        $r = app(SystemHealth::class)->run();
        $this->assertTrue($r['scheduler']['ok']);
        $this->assertSame([false, 'Ainda não deu sinal de vida.'], [$r['worker']['ok'], $r['worker']['detail']]);

        (new WorkerHeartbeatJob())->handle();
        $this->assertTrue(app(SystemHealth::class)->run()['worker']['ok'], 'o job do worker bate');

        $this->travel(6)->minutes();
        $r = app(SystemHealth::class)->run();
        $this->assertFalse($r['scheduler']['ok']);
        $this->assertStringStartsWith('Sem sinal de vida desde', $r['scheduler']['detail']);
        $this->assertTrue($r['worker']['ok'], 'o worker tem 10 minutos de margem');
    }

    public function test_services_not_used_here_are_skipped_and_the_disk_has_a_limit(): void
    {
        $this->beats();
        $this->probes->antivirus = HealthProbes::SKIPPED;
        $this->probes->disk = 95.0;
        $r = app(SystemHealth::class)->run();
        $this->assertSame([true, true], [$r['antivirus']['ok'], $r['antivirus']['skipped']]);
        $this->assertSame([false, 'Disco a 95% (limite de 90%).'], [$r['disk']['ok'], $r['disk']['detail']]);
    }

    public function test_a_failure_is_warned_once_after_10_minutes_and_again_when_it_comes_back(): void
    {
        config(['scheduler.external_jobs' => true]);
        $this->probes->redis = 'O Redis não responde (fila e cache).';

        $this->assertSame([], $this->watch()['alerted']);
        $this->travel(5)->minutes();
        $this->assertSame([], $this->watch()['alerted']);
        $this->travel(5)->minutes();
        $this->assertSame(['redis'], $this->watch()['alerted']);
        $this->assertSame([], $this->watch()['alerted'], 'uma só vez');

        Mail::assertSent(AgencyNoticeMail::class, 1);
        Mail::assertSent(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->title === 'Falha no servidor'
            && $m->hasTo('root@xplendor.invalid') && $m->hasTo('alerta@exemplo.invalid')
            && str_contains(implode(' ', $m->lines), 'Redis (fila e cache): O Redis não responde'));
        Mail::assertNothingQueued();
        $this->assertSame(1, Alert::where('company_id', $this->team->id)->where('title', 'Falha no servidor')->count());

        $this->probes->redis = null;
        $this->assertSame(['redis'], $this->watch()['recovered']);
        Mail::assertSent(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->title === 'Servidor recuperado');
        $this->assertSame([], $this->watch()['recovered'], 'a recuperação também se avisa uma só vez');
        $this->assertNull(DB::table(SystemHealth::TABLE)->where('name', 'redis')->value('failing_since'));
    }

    public function test_without_database_the_warning_goes_at_once_and_at_most_once_per_hour(): void
    {
        config(['scheduler.external_jobs' => true]);
        $this->probes->database = 'A base de dados não responde.';
        $this->assertSame(['database'], $this->watch()['alerted']);
        $this->assertSame([], $this->watch()['alerted']);
        Mail::assertSent(AgencyNoticeMail::class, fn (AgencyNoticeMail $m) => $m->hasTo('alerta@exemplo.invalid')
            && str_contains(implode(' ', $m->lines), 'Base de dados: A base de dados não responde.'));

        $this->probes->database = null;
        $this->assertSame(['database'], $this->watch()['recovered']);
    }

    public function test_a_short_failure_is_never_warned(): void
    {
        config(['scheduler.external_jobs' => true]);
        $this->probes->antivirus = 'O antivírus (ClamAV) não responde.';
        $this->watch();
        $this->travel(5)->minutes();
        $this->probes->antivirus = null;
        $this->assertSame(['alerted' => [], 'recovered' => []], array_intersect_key($this->watch(), ['alerted' => 1, 'recovered' => 1]));
        Mail::assertNothingSent();
    }

    public function test_with_external_calls_off_the_warning_stays_in_the_bell(): void
    {
        config(['scheduler.external_jobs' => false]);
        $this->probes->disk = 97.0;
        $this->watch();
        $this->travel(11)->minutes();
        $this->assertSame(['disk'], $this->watch()['alerted']);
        Mail::assertNothingSent();
        $this->assertSame(1, Alert::where('company_id', $this->team->id)->where('title', 'Falha no servidor')->count());
    }

    public function test_only_the_root_sees_the_details(): void
    {
        $this->beats();
        $this->probes->redis = 'O Redis não responde (fila e cache).';
        $data = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/health')->assertOk()->json('data');
        $this->assertFalse($data['healthy']);
        $redis = collect($data['checks'])->firstWhere('name', 'redis');
        $this->assertSame(['Redis (fila e cache)', false, 'O Redis não responde (fila e cache).'], [$redis['label'], $redis['ok'], $redis['detail']]);

        $admin = User::factory()->create(['company_id' => $this->team->id, 'role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/health')->assertForbidden();
    }

    public function test_the_heartbeats_and_the_watch_are_scheduled_and_internal(): void
    {
        config(['scheduler.external_jobs' => false]);
        $events = collect(app(Schedule::class)->events())->keyBy('description');
        foreach (['health-heartbeat-scheduler', 'health-heartbeat-worker', 'health-watch'] as $name) {
            $this->assertTrue($events->has($name), "{$name} não está agendada");
            $this->assertTrue($events[$name]->filtersPass($this->app), "{$name} é interna e corre sempre");
        }
        $this->assertSame('* * * * *', $events['health-heartbeat-worker']->expression);
        $this->assertSame('*/5 * * * *', $events['health-watch']->expression);
    }
}

/** Sondas simuladas: null é "bem"; um texto é a falha. */
class FakeHealthProbes extends HealthProbes
{
    public ?string $database = null;

    public ?string $redis = null;

    public ?string $antivirus = null;

    public ?float $disk = 40.0;

    public function __construct() {}

    public function database(): ?string
    {
        return $this->database;
    }

    public function redis(): ?string
    {
        return $this->redis;
    }

    public function antivirus(): ?string
    {
        return $this->antivirus;
    }

    public function diskPercent(): ?float
    {
        return $this->disk;
    }
}
