<?php

namespace App\Jobs;

use App\Models\ScraperExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class RunScraperJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(
        private readonly string $source,
        private readonly string $mode,
        private readonly array  $filters,
        private readonly int    $executionId,
    ) {}

    public function handle(): void
    {
        $execution = ScraperExecution::findOrFail($this->executionId);

        $execution->update([
            'status'     => 'running',
            'started_at' => now(),
        ]);

        $process = new Process([
            'docker', 'exec', env('SCRAPER_CONTAINER', 'xplendor-scraper'),
            'python', '/app/scraper.py',
            '--source', $this->source,
            '--mode', $this->mode,
            '--filters', json_encode($this->filters),
        ]);

        $process->setTimeout($this->timeout);
        $process->run();

        $output = $process->getOutput();
        $error  = $process->getErrorOutput();

        if ($process->isSuccessful()) {
            $execution->update([
                'status'      => 'success',
                'output'      => $output ?: null,
                'error'       => $error ?: null,
                'finished_at' => now(),
            ]);

            Log::info('RunScraperJob: concluido', [
                'execution_id' => $this->executionId,
                'source'       => $this->source,
            ]);
        } else {
            $execution->update([
                'status'      => 'failed',
                'output'      => $output ?: null,
                'error'       => $error ?: null,
                'finished_at' => now(),
            ]);

            Log::error('RunScraperJob: falhou', [
                'execution_id' => $this->executionId,
                'source'       => $this->source,
                'exit_code'    => $process->getExitCode(),
                'error'        => $error,
            ]);

            throw new \RuntimeException(
                "Scraper falhou para source '{$this->source}' (exit {$process->getExitCode()}): {$error}"
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        ScraperExecution::where('id', $this->executionId)
            ->where('status', 'running')
            ->update([
                'status'      => 'failed',
                'error'       => $exception->getMessage(),
                'finished_at' => now(),
            ]);

        Log::error('RunScraperJob: todas as tentativas esgotadas', [
            'execution_id' => $this->executionId,
            'source'       => $this->source,
            'error'        => $exception->getMessage(),
        ]);
    }
}
