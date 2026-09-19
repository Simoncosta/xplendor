<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ValidatePingwinConnectionJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Services\AlertService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Validação ASSÍNCRONA da ligação PingWin (correção do diagnóstico: o
 * síncrono falha no php-fpm sem docker socket). O job corre no worker (com
 * socket), valida (login→logout), atualiza o estado e NOTIFICA no sino com o
 * resultado — incluindo o MOTIVO REAL da falha (ex.: 401 credenciais inválidas).
 * O Python é substituído por um fake (override de invoke()); nada toca docker.
 */
class ValidatePingwinConnectionJobTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->resto = Company::create(['nipc' => '500004100', 'fiscal_name' => 'Resto Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
    }

    /** Fake do serviço: override de invoke() → resultado controlado, sem docker. */
    private function fakeService(bool $ok, ?string $error = null): PingwinService
    {
        return new class($ok, $error) extends PingwinService {
            public function __construct(private bool $ok, private ?string $error) {}
            protected function invoke(array $payload): array
            {
                return $this->ok ? ['ok' => true] : ['ok' => false, 'error' => $this->error];
            }
        };
    }

    /** Grava a integração no estado "a validar" (como o connect faz). */
    private function storePending(): void
    {
        $this->fakeService(true)->saveCredentials($this->resto->id, ['username' => 'yuko', 'database' => 'yuko'], 'segredo');
        $this->assertSame('validating', CompanyIntegration::where('company_id', $this->resto->id)->platform('pingwin')->value('status'));
    }

    public function test_job_serializes_per_company(): void
    {
        $job = new ValidatePingwinConnectionJob($this->resto->id);
        $mw = $job->middleware();

        $this->assertCount(1, $mw);
        $this->assertInstanceOf(WithoutOverlapping::class, $mw[0]);
        $ref = new \ReflectionProperty(WithoutOverlapping::class, 'key');
        $ref->setAccessible(true);
        // Mesma família de chave das buscas → nunca duas operações PingWin juntas.
        $this->assertSame("pingwin-sync:{$this->resto->id}", $ref->getValue($mw[0]));
    }

    public function test_success_marks_active_and_notifies(): void
    {
        $this->storePending();

        (new ValidatePingwinConnectionJob($this->resto->id))->handle($this->fakeService(true), app(AlertService::class));

        $integration = CompanyIntegration::where('company_id', $this->resto->id)->platform('pingwin')->first();
        $this->assertSame('active', $integration->status);
        $this->assertNull($integration->error_message);

        $alert = Alert::where('company_id', $this->resto->id)->latest()->first();
        $this->assertNotNull($alert);
        $this->assertSame('opportunity', $alert->type);
        $this->assertSame('Ligação PingWin validada', $alert->title);
        $this->assertSame('/restauracao', $alert->detail_path);
    }

    public function test_failure_marks_error_and_notifies_with_real_reason(): void
    {
        $this->storePending();

        // O motivo REAL que o diagnóstico viu (401) tem de chegar à notificação.
        $reason = 'RuntimeError: Passo 2 (/service/authenticate) falhou: HTTP 401 — Utilizador ou password inválida!';
        (new ValidatePingwinConnectionJob($this->resto->id))->handle($this->fakeService(false, $reason), app(AlertService::class));

        $integration = CompanyIntegration::where('company_id', $this->resto->id)->platform('pingwin')->first();
        $this->assertSame('error', $integration->status);
        $this->assertStringContainsString('Utilizador ou password inválida', (string) $integration->error_message);

        $alert = Alert::where('company_id', $this->resto->id)->latest()->first();
        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert->type);
        $this->assertSame('Falha ao ligar ao PingWin', $alert->title);
        // A notificação leva o MOTIVO REAL, não um genérico.
        $this->assertStringContainsString('Utilizador ou password inválida', $alert->message);
    }
}
