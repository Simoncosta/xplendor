<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CarMarketAggregate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MarkStaleAggregatesAsErrorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 60;

    private const STALE_THRESHOLD_MINUTES = 10;

    /**
     * Marks car_market_aggregates stuck in pending/running for more than
     * STALE_THRESHOLD_MINUTES as error. Prevents zombie aggregates from
     * blocking UI indefinitely when the worker is down or the scraper hangs.
     */
    public function handle(): void
    {
        $stale = CarMarketAggregate::whereIn('status', ['pending', 'running'])
            ->where('created_at', '<', now()->subMinutes(self::STALE_THRESHOLD_MINUTES))
            ->get(['id', 'car_id', 'status', 'created_at']);

        if ($stale->isEmpty()) {
            return;
        }

        $ids = $stale->pluck('id')->toArray();

        CarMarketAggregate::whereIn('id', $ids)->update(['status' => 'error']);

        Log::warning('[MarkStaleAggregates] Aggregates marcados como error por timeout', [
            'count'    => count($ids),
            'ids'      => $ids,
            'car_ids'  => $stale->pluck('car_id')->toArray(),
            'statuses' => $stale->pluck('status', 'id')->toArray(),
        ]);
    }
}
