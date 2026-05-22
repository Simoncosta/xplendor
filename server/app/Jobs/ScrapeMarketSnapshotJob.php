<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Traits\ScraperProcess;
use App\Models\Car;
use App\Models\CarMarketAggregate;
use App\Repositories\CarMarketSnapshotRepository;
use App\Services\MarketSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ScrapeMarketSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ScraperProcess;

    public int $tries   = 1;
    public int $timeout = 200;

    public function __construct(
        private readonly int $carId,
        private readonly int $aggregateId,
    ) {}

    public function middleware(): array
    {
        return [new WithoutOverlapping("market-snapshot:{$this->carId}")];
    }

    public function handle(
        MarketSnapshotService $marketSnapshotService,
        CarMarketSnapshotRepository $snapshotRepository,
    ): void {
        $car = Car::with(['brand:id,name', 'model:id,name'])->find($this->carId);

        if (!$car) {
            Log::warning('ScrapeMarketSnapshotJob: car not found', ['car_id' => $this->carId]);
            return;
        }

        $vehicleType = $car->vehicle_type ?? 'car';

        $command = [
            'docker', 'exec',
            env('SCRAPER_CONTAINER', 'xplendor-scraper'),
            'python', '/scraper/main.py',
            '--source',       'standvirtual',
            '--mode',         'run',
            '--vehicle-type', $vehicleType,
            '--max-results',  '10',
            '--brand',        $car->brand->name,
            '--model',        $car->model->name,
        ];

        if ($car->registration_year) {
            $command[] = '--year-from';
            $command[] = (string) ($car->registration_year - 1);
            $command[] = '--year-to';
            $command[] = (string) ($car->registration_year + 1);
        }

        if ($car->fuel_type) {
            $command[] = '--fuel';
            $command[] = $car->fuel_type;
        }

        Log::info('ScrapeMarketSnapshotJob: starting scrape', [
            'car_id'       => $this->carId,
            'vehicle_type' => $vehicleType,
            'brand'        => $car->brand->name,
            'model'        => $car->model->name,
        ]);

        $process = new Process($command);
        $process->setTimeout(180);
        $process->run();

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        Log::info('ScrapeMarketSnapshotJob: scrape finished', [
            'car_id'    => $this->carId,
            'exit_code' => $process->getExitCode(),
        ]);

        if ($this->isBlocked($stdout, $stderr)) {
            Log::warning('ScrapeMarketSnapshotJob: Standvirtual bloqueou', [
                'car_id' => $this->carId,
                'stderr' => substr($stderr, 0, 500),
            ]);
            $marketSnapshotService->persistAggregateStatus($this->aggregateId, 'blocked');
            return;
        }

        if (!$process->isSuccessful()) {
            Log::error('ScrapeMarketSnapshotJob: scrape failed', [
                'car_id'    => $this->carId,
                'exit_code' => $process->getExitCode(),
                'stderr'    => substr($stderr, 0, 500),
            ]);
            $marketSnapshotService->persistAggregateStatus($this->aggregateId, 'error');
            return;
        }

        // Scraper sent fresh snapshots to DB via LaravelSender.
        // Now read comparables and compute the aggregate.
        $result = $marketSnapshotService->getComparables($car);

        $marketSnapshotService->computeAndPersistAggregate(
            $car,
            $this->aggregateId,
            $result['snapshots'],
            $result['fallback_used'],
        );

        Log::info('ScrapeMarketSnapshotJob: aggregate persisted', [
            'car_id'       => $this->carId,
            'aggregate_id' => $this->aggregateId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        CarMarketAggregate::where('id', $this->aggregateId)
            ->where('status', 'pending')
            ->update([
                'status'     => 'error',
                'updated_at' => now(),
            ]);

        Log::error('ScrapeMarketSnapshotJob: unhandled failure', [
            'car_id'       => $this->carId,
            'aggregate_id' => $this->aggregateId,
            'error'        => $exception->getMessage(),
        ]);
    }
}
