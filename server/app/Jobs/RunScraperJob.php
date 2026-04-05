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

        Log::info('RunScraperJob: filtros recebidos', $this->filters);

        $command = [
            'docker',
            'exec',
            env('SCRAPER_CONTAINER', 'xplendor-scraper'),
            'python',
            '/scraper/main.py',
            '--source',
            $this->source,
            '--mode',
            $this->mode,
        ];

        if ($this->mode === 'preview') {
            $command[] = '--preview-limit';
            $command[] = '10';
        }

        foreach (
            [
                'brand' => '--brand',
                'model' => '--model',
                'year_from' => '--year-from',
                'year_to' => '--year-to',
                'fuel' => '--fuel',
                'gearbox' => '--gearbox',
                'price_from' => '--price-from',
                'price_to' => '--price-to',
            ] as $key => $flag
        ) {
            if (!empty($this->filters[$key])) {
                $command[] = $flag;
                $command[] = (string) $this->filters[$key];
            }
        }

        Log::info('RunScraperJob: comando final', $command);

        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->run();

        $output = $process->getOutput();
        $error  = $process->getErrorOutput();

        Log::info('RunScraperJob: output bruto', ['output' => $output]);

        // 🔥 Extrair apenas JSON do output (mesmo com logs misturados)
        $json = $this->extractJson($output);
        $data = $json ? json_decode($json, true) : null;

        if ($process->isSuccessful()) {

            $execution->update([
                'status'            => 'success',
                'total_raw'         => $data['total_raw'] ?? 0,
                'total_normalized'  => $data['total_normalized'] ?? 0,
                'total_sent'        => $data['total_sent'] ?? 0,
                'total_failed'      => $data['total_failed'] ?? 0,
                'output'            => $output ?: null,
                'error'             => $error ?: null,
                'finished_at'       => now(),
            ]);

            Log::info('RunScraperJob: concluido', [
                'execution_id' => $this->executionId,
                'stats'        => $data,
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
                'exit_code'    => $process->getExitCode(),
                'error'        => $error,
            ]);

            throw new \RuntimeException(
                "Scraper falhou ({$process->getExitCode()}): {$error}"
            );
        }
    }

    /**
     * Extrai JSON válido do meio de logs
     */
    private function extractJson(string $output): ?string
    {
        $start = strpos($output, '{');
        $end   = strrpos($output, '}');

        if ($start === false || $end === false) {
            return null;
        }

        return substr($output, $start, $end - $start + 1);
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
            'error'        => $exception->getMessage(),
        ]);
    }
}
