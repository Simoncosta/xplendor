<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\CarMarketSnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpsertSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_vehicle_type_preserved_on_second_upsert_pass(): void
    {
        $repo = $this->app->make(CarMarketSnapshotRepository::class);

        $snapshot = [
            'external_id'     => 'sv-test-001',
            'source'          => 'standvirtual',
            'vehicle_type'    => 'motorhome',
            'title'           => 'Fiat Ducato 2.3',
            'url'             => 'https://standvirtual.com/anuncio/001',
            'brand'           => 'Fiat',
            'model'           => 'Ducato',
            'year'            => 2020,
            'price'           => 55000.00,
            'price_currency'  => 'EUR',
            'scraped_at'      => now()->toDateTimeString(),
            'created_at'      => now()->toDateTimeString(),
            'updated_at'      => now()->toDateTimeString(),
        ];

        // First upsert
        $repo->upsertSnapshots([$snapshot]);

        $this->assertDatabaseHas('car_market_snapshots', [
            'external_id'  => 'sv-test-001',
            'vehicle_type' => 'motorhome',
        ]);

        // Second upsert — vehicle_type must survive the update
        $snapshot['price'] = 53000.00;
        $repo->upsertSnapshots([$snapshot]);

        $this->assertDatabaseHas('car_market_snapshots', [
            'external_id'  => 'sv-test-001',
            'vehicle_type' => 'motorhome',
            'price'        => 53000.00,
        ]);
    }

    public function test_upsert_updates_price_on_second_pass(): void
    {
        $repo = $this->app->make(CarMarketSnapshotRepository::class);

        $base = [
            'external_id'    => 'sv-test-002',
            'source'         => 'standvirtual',
            'vehicle_type'   => 'car',
            'title'          => 'BMW 320d',
            'url'            => 'https://standvirtual.com/anuncio/002',
            'brand'          => 'BMW',
            'model'          => '3 Series',
            'year'           => 2021,
            'price'          => 30000.00,
            'price_currency' => 'EUR',
            'scraped_at'     => now()->toDateTimeString(),
            'created_at'     => now()->toDateTimeString(),
            'updated_at'     => now()->toDateTimeString(),
        ];

        $repo->upsertSnapshots([$base]);
        $base['price'] = 28500.00;
        $repo->upsertSnapshots([$base]);

        $this->assertDatabaseHas('car_market_snapshots', [
            'external_id' => 'sv-test-002',
            'price'       => 28500.00,
        ]);
    }
}
