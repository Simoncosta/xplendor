<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CmReservationSnapshot;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinLocation;
use App\Models\RestaurantForecast;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\CoverManagerClient;
use App\Services\CoverManagerService;
use App\Services\Restaurant\Forecast\ForecastMath;
use App\Services\Restaurant\Forecast\FutureReservationsService;
use App\Services\Restaurant\Forecast\PortugalHolidays;
use App\Services\Restaurant\Forecast\RestaurantForecastService;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fatia 6a · Calendário: a média sem extremos, os feriados móveis de 2026 e 2027 (e o municipal), o
 * backtest das 8 semanas, a escolha do modelo de amanhã (reservas só se o erro baixar), as anomalias,
 * as reservas futuras sem dados pessoais e o bloco do Calendário.
 */
class RestaurantForecastTest extends TestCase
{
    use RefreshDatabase;

    private int $c;
    private int $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-19 10:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->c = Company::create(['nipc' => '500009930', 'fiscal_name' => 'Yuko Lda', 'plan_id' => $planId, 'subscription_status' => 'active'])->id;
        app(CompanyModuleService::class)->applyPreset($this->c, 'restaurant');
        CompanyIntegration::create(['company_id' => $this->c, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);
        $this->loc = PingwinLocation::create(['company_id' => $this->c, 'winrest_store_id' => 'S1', 'display_name' => 'Costa Cabral', 'is_active' => true, 'opened_on' => '2026-01-10', 'cm_slug' => 'costa', 'cm_token' => 'tok'])->id;
    }

    /** Um dia real: faturação (€) e pessoas, com as pessoas reservadas na véspera ou antes. */
    private function day(string $date, float $eur, int $guests, ?int $reserved = null): void
    {
        DB::table('pingwin_daily_sales')->insert(['company_id' => $this->c, 'location_id' => $this->loc, 'business_date' => $date, 'invoiced_cents' => (int) round($eur * 100),
            'net_cents' => (int) round($eur * 100), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cm_reservation_shift_summary')->insert(['company_id' => $this->c, 'location_id' => $this->loc, 'business_date' => $date, 'shift' => 'dinner',
            'guests_total' => $guests, 'reservations_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        if ($reserved !== null) {
            DB::table('cm_reservation_leadtime_daily')->insert(['company_id' => $this->c, 'location_id' => $this->loc, 'business_date' => $date, 'bucket' => 'd1_2',
                'reservations_count' => 1, 'guests_total' => $reserved, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_mean_without_the_extremes(): void
    {
        $this->assertSame(25.0, ForecastMath::trimmedMean([10, 20, 30, 100]));
        $this->assertSame(20.0, ForecastMath::trimmedMean([30, 10, 20]));
        $this->assertNull(ForecastMath::trimmedMean([10, 20]));
        $this->assertSame(1.4, ForecastMath::reservationRatio(300, 100));   // com limites
        $this->assertSame(0.7, ForecastMath::reservationRatio(10, 100));
        $this->assertNull(ForecastMath::reservationRatio(10, 0));
        [$lo, $hi] = ForecastMath::interval(100, -0.2, 0.25);
        $this->assertEqualsWithDelta([80.0, 125.0], [$lo, $hi], 0.01);
    }

    public function test_movable_holidays_of_2026_and_2027_and_the_municipal_one(): void
    {
        $h26 = PortugalHolidays::forYear(2026, '24/06');
        $this->assertSame('Sexta-feira Santa', $h26['2026-04-03']);
        $this->assertSame('Páscoa', $h26['2026-04-05']);
        $this->assertSame('Corpo de Deus', $h26['2026-06-04']);
        $this->assertSame('Feriado municipal', $h26['2026-06-24']);
        $this->assertSame('Implantação da República', $h26['2026-10-05']);
        $h27 = PortugalHolidays::forYear(2027);
        $this->assertSame('Sexta-feira Santa', $h27['2027-03-26']);
        $this->assertSame('Páscoa', $h27['2027-03-28']);
        $this->assertSame('Corpo de Deus', $h27['2027-05-27']);
        $this->assertArrayNotHasKey('2027-06-24', $h27);
        $this->assertCount(13, $h27);
    }

    public function test_baseline_backtest_and_the_simple_mean_when_reservations_do_not_help(): void
    {
        // 12 semanas: segunda a 1 000 €, com uma semana a 2 000 € (o extremo sai) e as reservas sem relação.
        $start = CarbonImmutable::parse('2026-07-27');   // segunda
        for ($w = 0; $w < 12; $w++) {
            for ($d = 0; $d < 7; $d++) {
                $date = $start->addWeeks($w)->addDays($d)->toDateString();
                if ($date >= '2026-10-19') {
                    continue;
                }
                $eur = ($d === 0 && $w === 9) ? 2000 : 1000 + $d * 100;
                $this->day($date, $eur, 100 + $d * 10, ($w % 2) ? 40 : 10);
            }
        }
        $svc = new RestaurantForecastService($this->c);
        $s = $svc->series($this->loc);
        // A média das últimas 4 segundas, sem o maior (2 000) e o menor: 1 000.
        $this->assertSame(1000.0, $svc->baseline($s['invoiced'], '2026-10-19', '2026-10-19'));
        $bt = $svc->backtest($this->loc);
        $this->assertGreaterThan(40, count($bt['invoiced']['media_d1']['errors']));
        $this->assertSame('2026-10-18', $bt['invoiced']['media_d1']['to']);
        $this->assertNotContains('2026-10-05', [$bt['invoiced']['media_d1']['from']]);   // o feriado fica de fora
        $l = PingwinLocation::find($this->loc);
        $errors = $svc->saveErrors($l);
        $this->assertLessThan(3, $errors['invoiced']['media_d1']['mape']);
        // As reservas oscilam sem relação com as vendas: pioram o erro → amanhã fica a média simples, e diz porquê.
        $choice = RestaurantForecastService::tomorrowModel($errors, 'invoiced');
        $this->assertSame('media', $choice['model']);
        $this->assertStringStartsWith('Média simples: com as reservas, o erro medido nas últimas 8 semanas não baixou', $choice['reason']);
        $svc->forecast($l, $errors);
        $f = RestaurantForecast::where('location_id', $this->loc)->whereDate('business_date', '2026-10-26')->where('metric', 'invoiced')->sole();
        $this->assertSame([1000.0, 'media'], [$f->value, $f->model]);
        $this->assertNotNull($f->low);
        $this->assertSame(31 * 2, RestaurantForecast::where('location_id', $this->loc)->count());   // hoje + 30 dias, 2 indicadores
    }

    public function test_tomorrow_uses_reservations_only_when_the_measured_error_is_lower(): void
    {
        $e = fn ($res, $base, $n = 40) => ['invoiced' => ['reservas_d1' => ['mape' => $res, 'n' => $n], 'media_d1_res' => ['mape' => $base, 'n' => $n]]];
        $this->assertSame('reservas', RestaurantForecastService::tomorrowModel($e(10.0, 16.0), 'invoiced')['model']);
        $this->assertSame('Ajustada pelas reservas: o erro medido nas últimas 8 semanas baixou de 16,0 % para 10,0 %.', RestaurantForecastService::tomorrowModel($e(10.0, 16.0), 'invoiced')['reason']);
        $this->assertSame('media', RestaurantForecastService::tomorrowModel($e(18.0, 16.0), 'invoiced')['model']);
        $this->assertSame('media', RestaurantForecastService::tomorrowModel($e(10.0, 16.0, 5), 'invoiced')['model']);   // poucos dias
        $this->assertSame('media', RestaurantForecastService::tomorrowModel([], 'invoiced')['model']);
    }

    public function test_tomorrow_adjusted_by_reservations_when_they_help(): void
    {
        // As vendas acompanham as reservas da véspera: o modelo com reservas erra menos.
        $start = CarbonImmutable::parse('2026-07-27');
        for ($i = 0; $i < 84; $i++) {
            $date = $start->addDays($i)->toDateString();
            if ($date >= '2026-10-19') {
                break;
            }
            $reserved = 20 + (($i * 7) % 5) * 10;   // 20 a 60
            $this->day($date, 500 + $reserved * 20, 50 + $reserved, $reserved);
        }
        $svc = new RestaurantForecastService($this->c);
        $l = PingwinLocation::find($this->loc);
        $errors = $svc->saveErrors($l);
        $this->assertLessThan($errors['invoiced']['media_d1_res']['mape'], $errors['invoiced']['reservas_d1']['mape']);
        CmReservationSnapshot::create(['company_id' => $this->c, 'location_id' => $this->loc, 'business_date' => '2026-10-20', 'read_at' => now(), 'lead_days' => 1,
            'tables_count' => 9, 'guests_total' => 60]);
        $svc->forecast($l, $errors);
        $f = RestaurantForecast::where('location_id', $this->loc)->whereDate('business_date', '2026-10-20')->where('metric', 'invoiced')->sole();
        $this->assertSame('reservas', $f->model);
        $this->assertStringStartsWith('Ajustada pelas reservas', (string) $f->note);
    }

    public function test_anomalies_and_the_calendar_block(): void
    {
        Settings::setCompany($this->c, ['calendar.municipal_holiday' => '24/06'], null);
        $start = CarbonImmutable::parse('2026-08-03');
        for ($i = 0; $i < 77; $i++) {
            $date = $start->addDays($i)->toDateString();
            if ($date >= '2026-10-19') {
                break;
            }
            $eur = $date === '2026-10-13' ? 620 : 1000;   // a terça 13/10 faturou 38 % abaixo
            $this->day($date, $eur, 100);
        }
        $svc = new RestaurantForecastService($this->c);
        $svc->run();
        $an = $svc->anomalies($this->loc, '2026-10-01', '2026-10-18', ['invoiced' => ['media_d1' => ['p10' => -0.05, 'p90' => 0.05, 'n' => 40]]]);
        $this->assertSame('Faturou 38 % abaixo do previsto.', $an['2026-10-13'][0]['text']);
        $this->assertSame('Teve 20 % menos pessoas do que o previsto.', RestaurantForecastService::anomalyText('guests', -20));

        $admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin']);
        $r = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$this->c}/analytics/pingwin/calendar?month=2026-10")->assertOk();
        $fc = $r->json('data.forecast');
        $this->assertSame('2026-10-19', $fc['today']);
        $this->assertSame('2026-10-19', $fc['days'][0]['date']);
        $this->assertNotNull($fc['days'][0]['invoiced']['value']);
        $this->assertSame('Implantação da República', $fc['holidays']['2026-10-05']);
        $this->assertSame('Costa Cabral', $fc['errors'][0]['name']);
        $this->assertNotNull($fc['errors'][0]['invoiced']['days_2_30']);
        $june = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$this->c}/analytics/pingwin/calendar?month=2026-06")->json('data.forecast.holidays');
        $this->assertSame(['2026-06-04' => 'Corpo de Deus', '2026-06-10' => 'Dia de Portugal', '2026-06-24' => 'Feriado municipal'], $june);
    }

    public function test_future_reservations_keep_only_numbers(): void
    {
        $client = new class extends CoverManagerClient {
            public function getReservationsRange(string $token, string $slug, string $from, string $to, ?string $baseUrl = null): array
            {
                return [
                    ['date' => '2026-10-20', 'for' => '4', 'status' => '1', 'provenance' => 'terceros', 'email' => 'x@y.pt', 'user_phone' => '91', 'first_name' => 'Ana'],
                    ['date' => '2026-10-20', 'for' => '2', 'status' => '1', 'provenance' => 'app-movil'],
                    ['date' => '2026-10-20', 'for' => '6', 'status' => '-1', 'provenance' => 'terceros'],
                    ['date' => '2026-10-22', 'for' => '3', 'status' => '2', 'provenance' => ''],
                ];
            }
        };
        $svc = new FutureReservationsService(app(CoverManagerService::class), $client);
        $r = $svc->sync($this->c, CarbonImmutable::parse('2026-10-19 09:30', 'Europe/Lisbon'));
        $this->assertSame(['locations' => 1, 'days' => 31, 'failed' => []], $r);
        $d = CmReservationSnapshot::whereDate('business_date', '2026-10-20')->sole();
        $this->assertSame([2, 6, 1], [$d->tables_count, $d->guests_total, $d->lead_days]);
        $this->assertSame(['tables' => 1, 'guests' => 6], $d->by_status['cancelled']);
        $this->assertSame(['terceros' => 1, 'app-movil' => 1], $d->by_channel);
        $this->assertStringNotContainsString('x@y.pt', json_encode(CmReservationSnapshot::all()->toArray()));
        $this->assertSame(0, CmReservationSnapshot::whereDate('business_date', '2026-10-21')->value('tables_count'));   // os dias sem reservas também
    }
}
