<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncRestaurantJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\PingwinLocation;
use App\Models\User;
use App\Services\AlertService;
use App\Services\CompanyModuleService;
use App\Services\CoverManagerService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Sincronização por PERÍODO: um job por dia (SyncRestaurantJob) num
 * batch, com UMA notificação no fim. Cobre: um job por dia; teto de dias; gate
 * do módulo; e o modo período do job (não notifica por dia; lança em erro).
 */
class SyncPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;
    private Company $auto;
    private User $restoUser;
    private User $autoUser;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->resto = Company::create(['nipc' => '500010100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->auto = Company::create(['nipc' => '500010101', 'fiscal_name' => 'Auto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');
        PingwinLocation::create(['company_id' => $this->resto->id, 'winrest_store_id' => '10', 'display_name' => 'Baixa', 'is_active' => true]);
        $this->restoUser = User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);
        $this->autoUser = User::factory()->create(['company_id' => $this->auto->id, 'role' => 'admin']);
    }

    private function url(int $companyId): string
    {
        return "/api/v1/companies/{$companyId}/integrations/restaurant/sync-period";
    }

    public function test_dispatches_one_job_per_day_in_a_batch(): void
    {
        Bus::fake();

        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->resto->id), ['from' => '2026-06-01', 'to' => '2026-06-05'])
            ->assertStatus(200)
            ->assertJsonPath('data.days', 5);

        // Um batch com 5 jobs (um por dia), todos SyncRestaurantJob em modo período.
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 5
                && $batch->jobs->every(fn ($j) => $j instanceof SyncRestaurantJob && $j->notify === false);
        });
    }

    public function test_period_cap_is_enforced(): void
    {
        Bus::fake();

        // > 92 dias → 422 e nada despachado.
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->resto->id), ['from' => '2026-01-01', 'to' => '2026-12-31'])
            ->assertStatus(422);

        Bus::assertNothingBatched();
    }

    public function test_end_before_start_is_rejected(): void
    {
        Bus::fake();
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->resto->id), ['from' => '2026-06-10', 'to' => '2026-06-01'])
            ->assertStatus(422);
        Bus::assertNothingBatched();
    }

    public function test_module_gated_and_tenancy(): void
    {
        Bus::fake();
        $this->actingAs($this->autoUser, 'sanctum')
            ->postJson($this->url($this->auto->id), ['from' => '2026-06-01', 'to' => '2026-06-02'])
            ->assertStatus(403);
        Bus::assertNothingBatched();
    }

    // ── Modo PERÍODO do job: não notifica por dia; lança em erro (batch conta falha) ─

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

    public function test_period_job_does_not_notify_per_day_on_success(): void
    {
        (new SyncRestaurantJob($this->resto->id, '2026-06-01', notify: false))
            ->handle($this->fakePingwin(), app(CoverManagerService::class), app(AlertService::class));

        // Modo período → NENHUMA notificação por dia (o batch notifica no fim).
        $this->assertSame(0, Alert::where('company_id', $this->resto->id)->count());
    }

    public function test_period_job_throws_on_problem_so_batch_counts_failure(): void
    {
        $this->expectException(\RuntimeException::class);

        (new SyncRestaurantJob($this->resto->id, '2026-06-01', notify: false))
            ->handle($this->fakePingwin(fail: true), app(CoverManagerService::class), app(AlertService::class));
    }
}
