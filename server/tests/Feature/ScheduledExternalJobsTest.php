<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * P0 da noite dos Ficheiros: no dev, as tarefas agendadas que chamam serviços externos (Meta,
 * PingWin, CoverManager, Carmine, Standvirtual, IA) ficam desligadas por omissão; em produção,
 * nada muda; SCHEDULE_EXTERNAL_JOBS liga-as.
 */
class ScheduledExternalJobsTest extends TestCase
{
    private function events(): array
    {
        $out = [];
        foreach (app(Schedule::class)->events() as $e) {
            $out[$e->description] = $e;
        }

        return $out;
    }

    public function test_every_external_job_name_exists_in_the_schedule(): void
    {
        $missing = array_diff(config('scheduler.external_job_names'), array_keys($this->events()));
        $this->assertSame([], array_values($missing), 'um nome da lista não existe no agendamento');
    }

    public function test_off_the_external_jobs_do_not_run_and_the_internal_ones_still_do(): void
    {
        config(['scheduler.external_jobs' => false]);
        $events = $this->events();
        foreach (config('scheduler.external_job_names') as $name) {
            $this->assertFalse($events[$name]->filtersPass($this->app), "{$name} não devia correr");
        }
        foreach (['media-retention', 'ocr-purge-deleted-files', 'blogs-publish-scheduled', 'quotes-expire'] as $name) {
            $this->assertTrue($events[$name]->filtersPass($this->app), "{$name} (interna) devia correr");
        }
    }

    public function test_on_they_run(): void
    {
        config(['scheduler.external_jobs' => true]);
        foreach (config('scheduler.external_job_names') as $name) {
            $this->assertTrue($this->events()[$name]->filtersPass($this->app), "{$name} devia correr");
        }
    }

    public function test_the_default_is_on_in_production_and_off_elsewhere(): void
    {
        $cfg = fn (string $env, ?string $flag) => (function () use ($env, $flag) {
            $vars = ['APP_ENV' => $env, 'SCHEDULE_EXTERNAL_JOBS' => $flag];
            foreach ($vars as $k => $v) {
                $v === null ? putenv($k) : putenv("{$k}={$v}");
                $v === null ? (function () use ($k) { unset($_ENV[$k], $_SERVER[$k]); })() : ($_ENV[$k] = $_SERVER[$k] = $v);
            }

            return (require config_path('scheduler.php'))['external_jobs'];
        })();
        try {
            $this->assertTrue($cfg('production', null));
            $this->assertFalse($cfg('local', null));
            $this->assertTrue($cfg('local', 'true'));
            $this->assertFalse($cfg('production', 'false'));
        } finally {
            putenv('APP_ENV=testing');
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
            putenv('SCHEDULE_EXTERNAL_JOBS');
            unset($_ENV['SCHEDULE_EXTERNAL_JOBS'], $_SERVER['SCHEDULE_EXTERNAL_JOBS']);
        }
    }
}
