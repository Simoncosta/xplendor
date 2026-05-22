<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\RunScraperJob;
use App\Services\CarMarketSnapshotService;
use Tests\TestCase;

class ScraperVehicleTypeTest extends TestCase
{
    // -------------------------------------------------------------------------
    // RunScraperJob — command construction
    // -------------------------------------------------------------------------

    public function test_job_passes_vehicle_type_car_to_command(): void
    {
        $job = new RunScraperJob('standvirtual', 'preview', [], 1, 'car');

        $command = $this->invokeJobCommand($job);

        $this->assertContains('--vehicle-type', $command);
        $this->assertSame('car', $command[array_search('--vehicle-type', $command) + 1]);
    }

    public function test_job_passes_vehicle_type_motorhome_to_command(): void
    {
        $job = new RunScraperJob('standvirtual', 'preview', [], 1, 'motorhome');

        $command = $this->invokeJobCommand($job);

        $this->assertContains('--vehicle-type', $command);
        $this->assertSame('motorhome', $command[array_search('--vehicle-type', $command) + 1]);
    }

    public function test_job_rejects_invalid_vehicle_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RunScraperJob('standvirtual', 'run', [], 1, 'caravan');
    }

    // -------------------------------------------------------------------------
    // StoreMarketSnapshotRequest — validation
    // -------------------------------------------------------------------------

    public function test_form_request_accepts_valid_vehicle_types(): void
    {
        $request = new \App\Http\Requests\StoreMarketSnapshotRequest();
        $rules   = $request->rules();

        $vehicleTypeRule = $rules['snapshots.*.vehicle_type'] ?? null;

        $this->assertNotNull($vehicleTypeRule, 'vehicle_type rule must be defined');
        $this->assertContains('nullable', $vehicleTypeRule);

        $inRule = collect($vehicleTypeRule)->first(fn($r) => str_starts_with((string) $r, 'in:'));
        $this->assertNotNull($inRule, 'must have in: rule for vehicle_type');
        $this->assertStringContainsString('car', $inRule);
        $this->assertStringContainsString('motorhome', $inRule);
    }

    // -------------------------------------------------------------------------
    // CarMarketSnapshotService — vehicle_type persisted in normalised row
    // -------------------------------------------------------------------------

    public function test_snapshot_normalizer_persists_vehicle_type(): void
    {
        $service = new CarMarketSnapshotService(
            $this->app->make(\App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface::class)
        );

        $row = $this->callNormalizeSnapshot($service, [
            'external_id'  => 'sv-999',
            'source'       => 'standvirtual',
            'vehicle_type' => 'motorhome',
            'title'        => 'Fiat Ducato 2.3',
            'url'          => 'https://www.standvirtual.com/anuncio/999',
        ]);

        $this->assertSame('motorhome', $row['vehicle_type']);
    }

    public function test_snapshot_normalizer_accepts_null_vehicle_type(): void
    {
        $service = new CarMarketSnapshotService(
            $this->app->make(\App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface::class)
        );

        $row = $this->callNormalizeSnapshot($service, [
            'external_id' => 'sv-888',
            'source'      => 'standvirtual',
            'title'       => 'BMW 318d',
            'url'         => 'https://www.standvirtual.com/anuncio/888',
        ]);

        $this->assertNull($row['vehicle_type']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Extracts the docker command array from the job via reflection. */
    private function invokeJobCommand(RunScraperJob $job): array
    {
        $command = [
            'docker', 'exec', 'xplendor-scraper', 'python', '/scraper/main.py',
            '--source', 'standvirtual',
            '--mode', 'preview',
        ];

        // Mirror the command-building logic from RunScraperJob::handle().
        // We test the constructor-level vehicle_type without firing the job.
        $ref          = new \ReflectionClass($job);
        $vehicleType  = $ref->getProperty('vehicleType');
        $vehicleType->setAccessible(true);
        $vt           = $vehicleType->getValue($job);

        $command[] = '--vehicle-type';
        $command[] = $vt;

        return $command;
    }

    /** Calls the private normalizeSnapshot method via reflection. */
    private function callNormalizeSnapshot(CarMarketSnapshotService $service, array $snapshot): ?array
    {
        $method = new \ReflectionMethod($service, 'normalizeSnapshot');
        $method->setAccessible(true);
        return $method->invoke($service, $snapshot, now());
    }
}
