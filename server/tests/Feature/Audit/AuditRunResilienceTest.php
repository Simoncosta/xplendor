<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Audit\AuditEngine;
use App\Audit\AuditFindingData;
use App\Audit\AuditRuleBase;
use App\Audit\AuditRunService;
use App\Audit\AuditScope;
use App\Jobs\RunAuditJob;
use App\Jobs\RunAuditRuleJob;
use App\Models\AiRequest;
use App\Models\AuditRun;
use App\Models\Company;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiPrompt;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Uma regra de teste: o comportamento vem de um closure estático (por código). */
abstract class ResilienceRule extends AuditRuleBase
{
    /** @var array<string, \Closure> */
    public static array $behaviour = [];

    public function area(): string
    {
        return 'restauracao';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'Regra ' . $this->code();
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $fn = self::$behaviour[$this->code()] ?? null;
        if ($fn) {
            $fn();
        }
        yield new AuditFindingData(entityType: 'artigo', entityId: $this->code(), entityLabel: 'Artigo', title: 'Achado', why: 'Porque.', suggestion: 'Corrija.');
    }
}
final class ResilienceA extends ResilienceRule
{
    public function code(): string
    {
        return 'teste.a';
    }
}
final class ResilienceB extends ResilienceRule
{
    public function code(): string
    {
        return 'teste.b';
    }
}
final class ResilienceC extends ResilienceRule
{
    public function code(): string
    {
        return 'teste.c';
    }
}

/**
 * Auditoria · a corrida que não cai: um job por regra (com o seu limite de tempo), a regra que passa o
 * limite e a que falha ficam marcadas e a corrida continua, nenhuma IA nem serviço externo durante a
 * auditoria, a corrida presa dada como falhada com o passo, as entregas repetidas sem efeito e os
 * valores da fila (cada job abaixo do retry_after).
 */
class AuditRunResilienceTest extends TestCase
{
    use RefreshDatabase;

    private int $c;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auditoria.rules' => [ResilienceA::class, ResilienceB::class, ResilienceC::class]]);
        ResilienceRule::$behaviour = [];
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->c = Company::create(['nipc' => '500009930', 'fiscal_name' => 'Yuko Lda', 'plan_id' => $planId, 'subscription_status' => 'active'])->id;
        app(CompanyModuleService::class)->applyPreset($this->c, 'restaurant');
    }

    /** Começa uma corrida com a fila presa (Queue::fake) e devolve-a. */
    private function startRun(): AuditRun
    {
        Queue::fake();
        $run = app(AuditRunService::class)->start($this->c, 'restauracao', 'central');
        (new RunAuditJob($run->id))->handle(app(AuditEngine::class));

        return $run->fresh();
    }

    /** Corre os passos pela ordem: os que falham por timeout simulam o worker (failed() com a exceção). */
    private function drive(AuditRun $run, array $timeouts = []): AuditRun
    {
        foreach (array_keys($run->steps) as $i) {
            $job = new RunAuditRuleJob($run->id, $i);
            if (isset($timeouts[$i])) {
                $job->failed($timeouts[$i]);
            } else {
                $job->handle(app(AuditEngine::class));
            }
        }

        return $run->fresh();
    }

    public function test_each_rule_is_its_own_job_with_the_configured_time_limit(): void
    {
        config(['auditoria.rule_timeout' => 90]);
        $run = $this->startRun();
        Queue::assertPushed(RunAuditRuleJob::class, fn ($j) => $j->runId === $run->id && $j->index === 0 && $j->timeout === 90 && $j->tries === 1 && $j->failOnTimeout);
        $this->assertSame('running', $run->status);
        (new RunAuditRuleJob($run->id, 0))->handle(app(AuditEngine::class));
        Queue::assertPushed(RunAuditRuleJob::class, fn ($j) => $j->index === 1);
    }

    public function test_a_rule_over_the_time_limit_is_marked_and_the_run_goes_on(): void
    {
        $run = $this->drive($this->startRun(), [1 => new TimeoutExceededException('RunAuditRuleJob has timed out.')]);
        $this->assertSame(['ok', 'timeout', 'ok'], array_column($run->steps, 'status'));
        $this->assertStringStartsWith('Demorou demasiado: passou o limite de 120 s.', $run->steps[1]['error']);
        $this->assertSame('done', $run->status);
        $this->assertSame('Uma regra não conseguiu correr; as outras correram.', $run->error);
        $this->assertArrayHasKey('queries', $run->steps[0]);
        $this->assertArrayHasKey('memory_mb', $run->steps[0]);
    }

    public function test_a_failing_rule_and_a_dead_process_are_marked_and_the_run_goes_on(): void
    {
        ResilienceRule::$behaviour['teste.a'] = fn () => throw new \RuntimeException('Coluna em falta.');
        $run = $this->drive($this->startRun(), [2 => new MaxAttemptsExceededException('attempted too many times')]);
        $this->assertSame(['failed', 'ok', 'failed'], array_column($run->steps, 'status'));
        $this->assertSame('Coluna em falta.', $run->steps[0]['error']);
        $this->assertStringContainsString('O processo parou a meio', $run->steps[2]['error']);
        $this->assertSame('done', $run->status);
    }

    public function test_the_audit_never_calls_the_ai_or_external_services(): void
    {
        Http::fake();
        ResilienceRule::$behaviour['teste.a'] = fn () => app(AiGateway::class)->generate('unit_advisor', new AiPrompt('s', 'u'));
        ResilienceRule::$behaviour['teste.b'] = fn () => (new \ReflectionMethod(PingwinService::class, 'invoke'))->invoke(app(PingwinService::class), ['mode' => 'catalog']);
        $before = AiRequest::count();
        $run = $this->drive($this->startRun());
        $this->assertSame(['failed', 'failed', 'ok'], array_column($run->steps, 'status'));
        $this->assertSame('A auditoria não chama a IA durante a corrida: usa só o que estiver guardado.', $run->steps[0]['error']);
        $this->assertSame('A auditoria não chama o PingWin durante a corrida: usa só o que estiver guardado.', $run->steps[1]['error']);
        $this->assertSame($before, AiRequest::count());
        Http::assertNothingSent();
        // Fora da auditoria, a guarda não se mete (o "Perguntar à IA" continua a funcionar).
        $this->assertFalse(\App\Audit\AuditGuard::active());
    }

    public function test_a_stuck_run_is_marked_failed_with_the_step_where_it_stopped(): void
    {
        $run = $this->startRun();
        (new RunAuditRuleJob($run->id, 0))->handle(app(AuditEngine::class));
        $steps = $run->fresh()->steps;
        $steps[1]['status'] = 'running';   // o worker morreu a meio da regra B
        DB::table('audit_runs')->where('id', $run->id)->update(['steps' => json_encode($steps), 'updated_at' => now()->subMinutes(31)]);
        $this->assertSame(1, app(AuditRunService::class)->expireStale());
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('A corrida parou no passo «Regra teste.b» (sem progresso há mais de 30 minutos) e foi dada como falhada.', $run->error);
        $this->assertSame(['ok', 'failed', 'pending'], array_column($run->steps, 'status'));
        // Uma corrida com progresso recente não é tocada.
        $fresh = $this->startRun();
        $this->assertSame(0, app(AuditRunService::class)->expireStale());
        $this->assertSame('running', $fresh->fresh()->status);
    }

    public function test_repeated_deliveries_do_nothing(): void
    {
        $run = $this->startRun();
        Queue::fake();
        (new RunAuditJob($run->id))->handle(app(AuditEngine::class));   // a mesma corrida entregue de novo
        Queue::assertNothingPushed();
        (new RunAuditRuleJob($run->id, 0))->handle(app(AuditEngine::class));
        $steps = $run->fresh()->steps;
        (new RunAuditRuleJob($run->id, 0))->handle(app(AuditEngine::class));   // o passo 0 outra vez
        $this->assertSame($steps, $run->fresh()->steps);
        $this->assertSame(1, \App\Models\AuditFinding::where('rule', 'teste.a')->count());
    }

    public function test_queue_values_every_job_below_retry_after_and_one_real_attempt(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        $this->assertGreaterThanOrEqual(3700, $retryAfter);
        config(['queue.connections.redis.retry_after' => null]);
        putenv('REDIS_QUEUE_RETRY_AFTER=60');   // um .env com um valor baixo não o baixa
        $config = require base_path('config/queue.php');
        putenv('REDIS_QUEUE_RETRY_AFTER');
        $this->assertSame(3700, $config['connections']['redis']['retry_after']);

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\' . basename($file, '.php');
            $ref = new \ReflectionClass($class);
            $job = $ref->newInstanceWithoutConstructor();
            $timeout = $ref->hasProperty('timeout') ? ($ref->getProperty('timeout')->isInitialized($job) ? $ref->getProperty('timeout')->getValue($job) : null) : null;
            if ($class === RunAuditRuleJob::class) {
                $timeout = (new RunAuditRuleJob(1, 0))->timeout;
            }
            if ($timeout !== null) {
                $this->assertLessThan($retryAfter, $timeout, "{$class}: o timeout tem de ficar abaixo do retry_after");
            }
            if (preg_match('/Pingwin|Audit/', $class) && $timeout !== null && $timeout >= 120) {
                $tries = $ref->hasProperty('tries') ? $ref->getProperty('tries')->getValue($job) : null;
                $maxExceptions = $ref->hasProperty('maxExceptions') ? $ref->getProperty('maxExceptions')->getValue($job) : null;
                $this->assertTrue($tries === 1 || $maxExceptions === 1, "{$class}: uma só execução real (tries = 1, ou maxExceptions = 1 com a espera do bloqueio)");
                $this->assertTrue($ref->hasProperty('failOnTimeout') && $ref->getProperty('failOnTimeout')->getValue($job), "{$class}: failOnTimeout");
            }
        }
        $this->assertSame([1, 1], [(new RunAuditJob(1))->tries, (new RunAuditRuleJob(1, 0))->tries]);
    }
}
