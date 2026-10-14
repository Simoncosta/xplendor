<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Jobs\CollectRadarFeedsJob;
use App\Models\Company;
use App\Models\RadarItem;
use App\Models\RadarRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P1 do fecho: o Radar nasce desligado (RADAR_ENABLED=false) em todos os ambientes. Desligado,
 * a recolha não faz nenhum pedido (nem pela tarefa, nem pelo comando sem --force) e o painel do
 * root diz que está desligado.
 */
class RadarSwitchTest extends TestCase
{
    use FakesRadarFeeds;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->robotsAlreadyRead();
        $this->fakeFeeds();
    }

    public function test_the_default_is_off(): void
    {
        $this->assertFalse((bool) (require config_path('radar.php'))['enabled']);
    }

    public function test_off_the_scheduled_job_makes_no_request_and_stores_nothing(): void
    {
        config(['radar.enabled' => false]);
        $out = app()->call([new CollectRadarFeedsJob(), 'handle']);
        $this->assertSame([], $out);
        Http::assertNothingSent();
        $this->assertSame(0, RadarRun::count());
        $this->assertSame(0, RadarItem::count());
    }

    public function test_off_the_command_says_so_and_only_force_reads(): void
    {
        config(['radar.enabled' => false]);
        $this->artisan('radar:collect', ['--execute' => true])
            ->expectsOutputToContain('Radar desligado (RADAR_ENABLED=false)')
            ->assertSuccessful();
        Http::assertNothingSent();

        $this->artisan('radar:collect', ['source' => 'jornal_eco', '--execute' => true, '--force' => true])->assertSuccessful();
        $this->assertSame(1, RadarRun::where('source_key', 'jornal_eco')->count());
        $this->assertGreaterThan(0, RadarItem::count());
    }

    public function test_on_the_scheduled_job_collects(): void
    {
        config(['radar.enabled' => true]);
        app()->call([new CollectRadarFeedsJob(), 'handle']);
        $this->assertGreaterThan(0, RadarItem::count());
    }

    public function test_the_root_panel_shows_the_switch(): void
    {
        $plan = \Illuminate\Support\Facades\DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $platform = Company::create(['nipc' => '500900002', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $plan, 'subscription_status' => 'active']);
        Sanctum::actingAs(User::factory()->create(['role' => 'root', 'company_id' => $platform->id]));

        config(['radar.enabled' => false]);
        $this->getJson('/api/v1/admin/radar')->assertOk()->assertJsonPath('data.radar_enabled', false);
        config(['radar.enabled' => true]);
        $this->getJson('/api/v1/admin/radar')->assertOk()->assertJsonPath('data.radar_enabled', true);
    }
}
