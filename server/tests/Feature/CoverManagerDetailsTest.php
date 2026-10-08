<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CmReservationChannelDaily;
use App\Models\CmReservationHourly;
use App\Models\CmReservationLeadtimeDaily;
use App\Models\CmReservationShiftSummary;
use App\Models\CmReservationStatusDaily;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinLocation;
use App\Services\CompanyModuleService;
use App\Services\CoverManagerService;
use App\Services\Restaurant\RestaurantDataQualityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * XPLENDOR — F2: agregados do CoverManager tirados da leitura que já se faz (hora, canal,
 * antecedência, códigos de estado) e faltas separadas das anulações. Sem dados pessoais.
 * Mapa dos códigos (documents/PINGWIN-F1-DESENHO.md §11): "1", "2", "3", "4", "5" válidas;
 * "-1", "-2", "-11" anuladas; "-3" falta; qualquer outro código fica "por classificar".
 */
class CoverManagerDetailsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private PingwinLocation $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 10:00:00');
        Sleep::fake();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009700', 'fiscal_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'covermanager', 'access_token' => 'EMPRESA-TOKEN', 'status' => 'active']);
        $this->loc = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '10', 'display_name' => 'Baixa', 'is_active' => true, 'cm_slug' => 'yuko-baixa']);
    }

    /** Reservas como o CoverManager as devolve (com dados pessoais que nunca podem ficar). */
    private function reservs(): array
    {
        $r = fn (array $x) => $x + ['user_name' => 'Maria Silva', 'email' => 'maria@x.pt', 'user_phone' => '911222333', 'meal_shift' => 'Jantar'];

        return [
            $r(['date' => '2026-10-06', 'time' => '20:30', 'for' => 4, 'status' => '3', 'provenance' => 'Online', 'date_add' => '2026-10-06']),
            $r(['date' => '2026-10-06', 'time' => '20:00', 'for' => 2, 'status' => '5', 'provenance' => 'Online', 'date_add' => '2026-10-04']),
            $r(['date' => '2026-10-06', 'time' => '13:15:00', 'for' => 6, 'status' => '5', 'provenance' => 'Telefone', 'date_add' => '2026-09-20', 'meal_shift' => 'Almoço']),
            $r(['date' => '2026-10-06', 'time' => '21:00', 'for' => 3, 'status' => '5', 'provenance' => 'Walk in', 'date_add' => '2026-10-06']),
            $r(['date' => '2026-10-06', 'time' => '21:00', 'for' => 5, 'status' => '-2', 'provenance' => 'Online', 'date_add' => '2026-10-01']),
            $r(['date' => '2026-10-06', 'time' => '22:00', 'for' => 2, 'status' => '-3', 'provenance' => 'Online', 'date_add' => '2026-08-01']),
        ];
    }

    public function test_no_shows_are_counted_apart_from_cancellations(): void
    {
        $agg = app(CoverManagerService::class)->aggregate($this->reservs());

        $this->assertSame(['guests_total' => 9, 'reservations_count' => 3, 'walk_ins_count' => 1, 'cancelled_count' => 1, 'no_show_count' => 1, 'unclassified_count' => 0], $agg['dinner']);
        $this->assertSame(1, $agg['lunch']['reservations_count']);
        $this->assertSame('cancelled', app(CoverManagerService::class)->statusKind('-1')); // outros "-": anulação, como até aqui
    }

    public function test_details_by_hour_channel_lead_time_and_status_code(): void
    {
        $d = app(CoverManagerService::class)->details($this->reservs(), '2026-10-06');

        $this->assertSame([13 => ['reservations_count' => 1, 'guests_total' => 6, 'walk_ins_count' => 0],
            20 => ['reservations_count' => 2, 'guests_total' => 6, 'walk_ins_count' => 0],
            21 => ['reservations_count' => 1, 'guests_total' => 3, 'walk_ins_count' => 1]], $d['hourly']);
        $this->assertSame(['online' => ['reservations_count' => 2, 'guests_total' => 6], 'telefone' => ['reservations_count' => 1, 'guests_total' => 6],
            'walk in' => ['reservations_count' => 1, 'guests_total' => 3]], $d['channels']);
        // Antecedência: sem walk-ins, anuladas nem faltas.
        $this->assertSame(['same_day' => ['reservations_count' => 1, 'guests_total' => 4], 'd1_2' => ['reservations_count' => 1, 'guests_total' => 2],
            'd8_30' => ['reservations_count' => 1, 'guests_total' => 6]], $d['lead']);
        $this->assertSame(['3' => 1, '5' => 3, '-2' => 1, '-3' => 1], $d['statuses']);
    }

    public function test_sync_stores_details_only_with_the_switch_on_and_never_personal_data(): void
    {
        Http::fake(['*' => Http::response(['reservs' => $this->reservs()], 200)]);
        $cover = app(CoverManagerService::class);

        $cover->sync($this->company->id, '2026-10-06');
        $this->assertSame(0, CmReservationHourly::count()); // interruptor desligado: só o resumo por turno
        $this->assertSame(1, CmReservationShiftSummary::where('shift', 'dinner')->value('no_show_count'));

        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        $cover->sync($this->company->id, '2026-10-06');
        $cover->sync($this->company->id, '2026-10-06'); // reler substitui, não duplica
        $this->assertSame(3, CmReservationHourly::count());
        $this->assertSame(3, CmReservationChannelDaily::count());
        $this->assertSame(3, CmReservationLeadtimeDaily::count());
        $this->assertSame(4, CmReservationStatusDaily::count());

        foreach (['cm_reservation_hourly', 'cm_reservation_channel_daily', 'cm_reservation_leadtime_daily', 'cm_reservation_status_daily'] as $table) {
            $dump = json_encode(DB::table($table)->get());
            foreach (['Maria', 'maria@x.pt', '911222333'] as $pii) {
                $this->assertStringNotContainsString($pii, $dump);
            }
            foreach (['user_name', 'email', 'user_phone', 'name', 'phone'] as $col) {
                $this->assertNotContains($col, Schema::getColumnListing($table));
            }
        }
    }

    public function test_history_command_requires_the_switch_and_reads_day_by_day_with_spacing(): void
    {
        Http::fake(['*' => Http::response(['reservs' => $this->reservs()], 200)]);
        $this->artisan('covermanager:history', ['company' => $this->company->id, '--days' => 3])
            ->expectsOutputToContain('interruptor está desligado')->assertFailed();
        Http::assertNothingSent();

        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        $this->artisan('covermanager:history', ['company' => $this->company->id, '--days' => 3])
            ->expectsOutputToContain('Concluído')->assertSuccessful();
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07'], CmReservationHourly::distinct()->orderBy('business_date')->pluck('business_date')
            ->map(fn ($d) => substr((string) $d, 0, 10))->all());
    }

    public function test_status_map_confirmed_on_the_covermanager_screen(): void
    {
        $svc = app(CoverManagerService::class);
        // Confirmados no ecrã: "1" e "2" reserva confirmada, "4" chegada, "-1" reserva cancelada.
        foreach (['1', '2', '3', '4', '5'] as $code) {
            $this->assertSame('valid', $svc->statusKind($code), "código {$code}");
        }
        foreach (['-1', '-2', '-11'] as $code) {
            $this->assertSame('cancelled', $svc->statusKind($code), "código {$code}");
        }
        $this->assertSame('no_show', $svc->statusKind('-3'));
        $this->assertSame('valid', $svc->statusKind(4)); // número em vez de texto
        // Fora do mapa: nunca contado às cegas (nem válida, nem anulada).
        foreach (['7', '-4', '-12', '01', '', null, 'confirmed'] as $code) {
            $this->assertSame('unclassified', $svc->statusKind($code), 'código ' . var_export($code, true));
        }
    }

    public function test_unknown_codes_stay_apart_from_valid_and_cancelled(): void
    {
        $r = fn (array $x) => $x + ['date' => '2026-10-06', 'meal_shift' => 'Jantar', 'provenance' => 'Online', 'date_add' => '2026-10-06', 'user_name' => 'Maria Silva'];
        $reservs = [...$this->reservs(),
            $r(['time' => '20:00', 'for' => 7, 'status' => '7']),
            $r(['time' => '21:00', 'for' => 3]), // sem estado
            $r(['time' => '21:30', 'for' => 2, 'status' => '1']),
            $r(['time' => '22:30', 'for' => 4, 'status' => '-11']),
        ];
        $svc = app(CoverManagerService::class);

        $agg = $svc->aggregate($reservs);
        // Jantar: as 3 válidas de antes + o "1"; anuladas "-2" e "-11"; os dois desconhecidos à parte.
        $this->assertSame(['guests_total' => 11, 'reservations_count' => 4, 'walk_ins_count' => 1, 'cancelled_count' => 2, 'no_show_count' => 1, 'unclassified_count' => 2], $agg['dinner']);

        $d = $svc->details($reservs, '2026-10-06');
        $this->assertSame(5, array_sum(array_column($d['channels'], 'reservations_count'))); // só as válidas
        $this->assertSame(1, $d['statuses']['7']);
        $this->assertSame(1, $d['statuses']['(vazio)']);
    }

    public function test_sync_logs_only_unknown_codes_and_the_card_shows_them(): void
    {
        $r = fn (array $x) => $x + ['date' => '2026-10-06', 'meal_shift' => 'Jantar', 'time' => '20:00', 'for' => 2, 'provenance' => 'Online', 'date_add' => '2026-10-06', 'user_name' => 'Maria Silva', 'email' => 'maria@x.pt'];
        Http::fake(['*' => Http::response(['reservs' => [...$this->reservs(), $r(['status' => '7']), $r(['status' => '7']), $r(['status' => '9'])]], 200)]);
        Log::spy();
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();

        app(CoverManagerService::class)->sync($this->company->id, '2026-10-06');

        $this->assertSame(3, (int) CmReservationShiftSummary::sum('unclassified_count'));
        $this->assertSame(4, (int) CmReservationShiftSummary::sum('reservations_count')); // os desconhecidos não entram
        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) {
            $dump = json_encode($context);

            return str_contains($message, 'por classificar') && $context['codes'] === ['7' => 2, '9' => 1]
                && ! str_contains($dump, 'Maria') && ! str_contains($dump, 'maria@x.pt');
        })->once();

        $card = app(RestaurantDataQualityService::class)->card($this->company->id);
        $this->assertSame(['days' => 90, 'unclassified' => 3, 'codes' => ['7' => 2, '9' => 1]], $card['reservations']);
    }

    public function test_card_shows_nothing_to_classify_when_every_code_is_mapped(): void
    {
        Http::fake(['*' => Http::response(['reservs' => $this->reservs()], 200)]);
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        app(CoverManagerService::class)->sync($this->company->id, '2026-10-06');

        $card = app(RestaurantDataQualityService::class)->card($this->company->id);
        $this->assertSame(['days' => 90, 'unclassified' => 0, 'codes' => []], $card['reservations']);
    }

    public function test_history_command_reports_unclassified_reservations(): void
    {
        $r = fn (array $x) => $x + ['date' => '2026-10-06', 'meal_shift' => 'Jantar', 'time' => '20:00', 'for' => 2, 'provenance' => 'Online', 'date_add' => '2026-10-06'];
        Http::fake(['*' => Http::response(['reservs' => [...$this->reservs(), $r(['status' => '7'])]], 200)]);
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();

        $this->artisan('covermanager:history', ['company' => $this->company->id, '--days' => 1])
            ->expectsOutputToContain('por classificar: 1')
            ->expectsOutputToContain('fora do mapa')
            ->assertSuccessful();
    }
}
