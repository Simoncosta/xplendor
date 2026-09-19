<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncPingwinJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\User;
use App\Services\AlertService;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Fundação do dashboard de restauração: a FILA de sincronização
 * PingWin + a notificação no sino. Cobre: o gatilho mete na fila sem travar e é
 * gated pelo módulo; o job serializa por empresa; a notificação dispara no fim
 * (sucesso e falha). O ciclo real (login→relatório→LOGOUT) é o PingwinService,
 * reutilizado — aqui é substituído por um fake para não tocar docker/rede.
 */
class SyncPingwinJobTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;
    private Company $auto;
    private User $restoUser;
    private User $autoUser;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->resto = Company::create(['nipc' => '500003100', 'fiscal_name' => 'Resto Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->auto = Company::create(['nipc' => '500003101', 'fiscal_name' => 'Auto Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');

        // O gatilho de sync exige pelo menos uma loja ativa cadastrada.
        \App\Models\PingwinLocation::create([
            'company_id' => $this->resto->id, 'winrest_store_id' => '111', 'display_name' => 'Loja', 'is_active' => true,
        ]);

        $this->restoUser = User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);
        $this->autoUser = User::factory()->create(['company_id' => $this->auto->id, 'role' => 'admin']);
    }

    private function url(int $companyId): string
    {
        return "/api/v1/companies/{$companyId}/integrations/pingwin/sync-queue";
    }

    // ── O gatilho mete na FILA (não trava) e é gated pelo módulo ───────────────

    public function test_trigger_queues_job_without_blocking(): void
    {
        Bus::fake();

        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson($this->url($this->resto->id), ['date' => '2026-09-17'])
            ->assertStatus(200)
            ->assertJsonPath('data.queued', true);

        // Etapa 4: o gatilho despacha a orquestração (PingWin + CoverManager).
        Bus::assertDispatched(\App\Jobs\SyncRestaurantJob::class, function (\App\Jobs\SyncRestaurantJob $job) {
            return $job->companyId === $this->resto->id && $job->date === '2026-09-17';
        });
    }

    public function test_trigger_is_blocked_for_company_without_pingwin_module(): void
    {
        Bus::fake();

        $this->actingAs($this->autoUser, 'sanctum')
            ->postJson($this->url($this->auto->id), ['date' => '2026-09-17'])
            ->assertStatus(403);

        Bus::assertNotDispatched(\App\Jobs\SyncRestaurantJob::class);
    }

    // ── O job serializa por empresa (WithoutOverlapping com a chave da empresa) ─

    public function test_job_serializes_per_company(): void
    {
        $job = new SyncPingwinJob($this->resto->id, '2026-09-17');
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        // A chave inclui o id da empresa → dois jobs da mesma empresa não correm juntos.
        $ref = new \ReflectionProperty(WithoutOverlapping::class, 'key');
        $ref->setAccessible(true);
        $this->assertSame("pingwin-sync:{$this->resto->id}", $ref->getValue($middleware[0]));
    }

    // ── A notificação dispara no sino no fim (sucesso e falha) ──────────────────

    public function test_job_notifies_bell_on_success(): void
    {
        // Fake do serviço: reutiliza a assinatura, sem tocar docker/rede.
        $fake = new class extends PingwinService {
            public function sync(int $companyId, ?string $date = null): array
            {
                return ['ok' => true, 'date' => $date];
            }
        };

        (new SyncPingwinJob($this->resto->id, '2026-09-17'))->handle($fake, app(AlertService::class));

        $alert = Alert::where('company_id', $this->resto->id)->latest()->first();
        $this->assertNotNull($alert);
        $this->assertNull($alert->car_id);                       // notificação de sistema (sem viatura)
        $this->assertSame('opportunity', $alert->type);
        $this->assertSame('Dados de vendas atualizados', $alert->title);
        $this->assertSame('/restauracao', $alert->detail_path);
        $this->assertFalse((bool) $alert->is_read);
    }

    public function test_job_notifies_bell_on_failure(): void
    {
        (new SyncPingwinJob($this->resto->id, '2026-09-17'))->failed(new \RuntimeException('boom'));

        $alert = Alert::where('company_id', $this->resto->id)->latest()->first();
        $this->assertNotNull($alert);
        $this->assertNull($alert->car_id);
        $this->assertSame('warning', $alert->type);
        $this->assertSame('Falha ao atualizar vendas', $alert->title);
        $this->assertSame('/restauracao', $alert->detail_path);
    }

    public function test_report_failure_surfaces_real_reason_in_notification(): void
    {
        // sync() lança com o MOTIVO REAL do relatório (não o genérico).
        $fake = new class extends PingwinService {
            public function sync(int $companyId, ?string $date = null): array
            {
                throw new \RuntimeException("Sincronização PingWin falhou: KeyError: 'report' (report_id inválido)");
            }
        };

        (new SyncPingwinJob($this->resto->id, '2026-09-17'))->handle($fake, app(AlertService::class));

        $alert = Alert::where('company_id', $this->resto->id)->latest()->first();
        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert->type);
        $this->assertSame('Falha ao atualizar vendas', $alert->title);
        // O motivo real chega à notificação (não um genérico).
        $this->assertStringContainsString('report_id inválido', $alert->message);
    }

    // ── O alerta de sistema aparece no sino (endpoint /alerts) com detail_path ─

    public function test_system_alert_is_served_to_the_bell(): void
    {
        (new SyncPingwinJob($this->resto->id, null))->failed(new \RuntimeException('boom'));

        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/alerts")
            ->assertStatus(200)
            ->assertJsonPath('data.0.detail_path', '/restauracao')
            ->assertJsonPath('data.0.car_id', null);
    }
}
