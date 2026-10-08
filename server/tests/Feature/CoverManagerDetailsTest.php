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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * XPLENDOR — F2: agregados do CoverManager tirados da leitura que já se faz (hora, canal,
 * antecedência, códigos de estado) e faltas separadas das anulações. Sem dados pessoais.
 * Os campos e os códigos seguem o projeto yukotavern ("3" confirmada, "5" concluída,
 * "-2" anulada, "-3" falta).
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

        $this->assertSame(['guests_total' => 9, 'reservations_count' => 3, 'walk_ins_count' => 1, 'cancelled_count' => 1, 'no_show_count' => 1], $agg['dinner']);
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
}
