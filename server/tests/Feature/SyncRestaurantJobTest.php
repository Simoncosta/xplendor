<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncRestaurantJob;
use App\Models\Alert;
use App\Models\CmReservationShiftSummary;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinLocation;
use App\Services\AlertService;
use App\Services\CoverManagerService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * XPLENDOR — Etapa 4: "buscar dados atualizados" orquestra PingWin + CoverManager
 * de TODAS as lojas e emite UMA notificação no fim (não uma por integração).
 */
class SyncRestaurantJobTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        // Token CoverManager de empresa + 2 lojas com slug (todas sincronizáveis).
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'covermanager', 'access_token' => 'EMPRESA-TOKEN', 'status' => 'active']);
        PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '10', 'display_name' => 'Baixa', 'is_active' => true, 'cm_slug' => 'baixa']);
        PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '20', 'display_name' => 'Costa', 'is_active' => true, 'cm_slug' => 'costa']);
    }

    /** PingWin fake (sem docker): sync() ok ou a rebentar, conforme $fail. */
    private function fakePingwin(bool $fail = false): PingwinService
    {
        return new class($fail) extends PingwinService {
            public function __construct(private bool $fail) {}
            public function sync(int $companyId, ?string $date = null): array
            {
                if ($this->fail) {
                    throw new \RuntimeException('vendas rebentaram');
                }
                return ['ok' => true];
            }
        };
    }

    public function test_serializes_per_company(): void
    {
        $mw = (new SyncRestaurantJob($this->company->id, '2026-09-18'))->middleware();
        $this->assertInstanceOf(WithoutOverlapping::class, $mw[0]);
        $ref = new \ReflectionProperty(WithoutOverlapping::class, 'key');
        $ref->setAccessible(true);
        $this->assertSame("pingwin-sync:{$this->company->id}", $ref->getValue($mw[0]));
    }

    public function test_runs_both_and_notifies_once_on_success(): void
    {
        Http::fake(['*' => Http::response(['reservs' => [
            ['meal_shift' => 'dinner', 'for' => 4, 'status' => 'ok'],
        ]], 200)]);

        (new SyncRestaurantJob($this->company->id, '2026-09-18'))
            ->handle($this->fakePingwin(), app(CoverManagerService::class), app(AlertService::class));

        // CoverManager correu para TODAS as lojas (2 slugs → 2 linhas de reservas).
        $this->assertSame(2, CmReservationShiftSummary::where('company_id', $this->company->id)->count());

        // UMA notificação no fim (não uma por integração).
        $alerts = Alert::where('company_id', $this->company->id)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame('opportunity', $alerts[0]->type);
        $this->assertSame('Dados atualizados', $alerts[0]->title);
    }

    public function test_partial_failure_gives_single_warning_with_reason(): void
    {
        Http::fake(['*' => Http::response(['reservs' => []], 200)]);

        // PingWin rebenta; CoverManager corre. UMA notificação de aviso com o motivo.
        (new SyncRestaurantJob($this->company->id, '2026-09-18'))
            ->handle($this->fakePingwin(fail: true), app(CoverManagerService::class), app(AlertService::class));

        $alerts = Alert::where('company_id', $this->company->id)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame('warning', $alerts[0]->type);
        $this->assertStringContainsString('vendas (PingWin)', $alerts[0]->message);
    }
}
