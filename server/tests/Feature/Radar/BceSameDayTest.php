<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Jobs\RecomputeImoveisCompassJob;
use App\Models\Company;
use App\Models\CompassSignal;
use App\Models\ContentSector;
use App\Services\CompanyModuleService;
use App\Services\Compass\Imoveis\ImoveisSignalService;
use App\Services\Radar\RadarCollector;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bússola 3: a jogada "Explicar a decisão do BCE" no próprio dia. Sai pela data do calendário
 * oficial (às 05:25), mesmo sem o comunicado e com o Radar desligado; quando o Radar recolhe o
 * comunicado nesse dia, as empresas com o módulo imoveis são recalculadas e a jogada ganha a ligação.
 * Fonte simulada (amostra gravada do BCE: decisão de 29/10/2026, 13:15 em Lisboa).
 */
class BceSameDayTest extends TestCase
{
    use FakesRadarFeeds;
    use RefreshDatabase;

    private const LINK = 'https://www.ecb.europa.eu/press/pr/date/2026/html/ecb.mp261029~5f1c0a7e2b.en.html';

    private Company $company;
    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $make = function (string $nipc, array $modules) use ($plan) {
            $c = Company::create(['nipc' => $nipc, 'fiscal_name' => "Empresa {$nipc} Lda", 'plan_id' => $plan, 'subscription_status' => 'active',
                'content_sector_id' => ContentSector::where('slug', 'imobiliario')->value('id')]);
            foreach ($modules as $m) {
                app(CompanyModuleService::class)->enable($c->id, $m);
            }

            return $c;
        };
        $this->company = $make('500019301', ['linha_editorial', 'imoveis']);
        $this->other = $make('500019302', ['linha_editorial']);
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

    private function bceSignal(): ?CompassSignal
    {
        return CompassSignal::where('provider', ImoveisSignalService::PROVIDER)->where('company_id', $this->company->id)->where('type', 'bce')->first();
    }

    private function collectCommunique(): void
    {
        app(RadarCollector::class)->collect('bce_press', true, ['fixture' => $this->fixturePath('bce/press.xml')]);
    }

    public function test_the_play_comes_out_by_the_calendar_without_the_communique_and_with_the_radar_off(): void
    {
        config(['radar.enabled' => false]);
        $this->at('2026-10-29 05:25:00');
        app(ImoveisSignalService::class)->compute($this->company->id);

        $signal = $this->bceSignal();
        $this->assertNotNull($signal, 'a jogada sai pela data do calendário oficial');
        $this->assertNull($signal->numbers['url'], 'ainda sem o comunicado');
        Http::assertNothingSent();
    }

    public function test_the_communique_on_the_decision_day_recomputes_the_real_estate_companies_and_the_play_gets_the_link(): void
    {
        $this->at('2026-10-29 05:25:00');
        app(ImoveisSignalService::class)->compute($this->company->id);
        $this->assertNull($this->bceSignal()->numbers['url']);

        $this->at('2026-10-29 13:30:00');
        Queue::fake();
        $this->collectCommunique();
        Queue::assertPushed(RecomputeImoveisCompassJob::class, fn ($job) => $job->companyId === $this->company->id);
        Queue::assertNotPushed(RecomputeImoveisCompassJob::class, fn ($job) => $job->companyId === $this->other->id);

        // O job recalcula: a jogada continua no próprio dia, agora com a ligação ao comunicado.
        (new RecomputeImoveisCompassJob($this->company->id))->handle(app(ImoveisSignalService::class));
        $this->assertSame(self::LINK, $this->bceSignal()->numbers['url']);
    }

    public function test_the_communique_outside_a_decision_day_recomputes_nothing(): void
    {
        $this->at('2026-10-30 09:00:00');
        Queue::fake();
        $this->collectCommunique();
        Queue::assertNotPushed(RecomputeImoveisCompassJob::class);
    }
}
