<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\RadarReactionHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Bússola 4: apaga do histórico das reações ao Radar as decisões com mais de 12 meses. Em lotes;
 * tarefa interna (radar-purge-history, 03:30 em Lisboa); o registo só leva a contagem.
 */
class PurgeRadarReactionHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const BATCH = 1000;

    public int $tries = 1;
    public int $timeout = 600;

    public function handle(): int
    {
        $cutoff = now()->subMonths(RadarReactionHistory::RETENTION_MONTHS);
        $total = 0;
        do {
            $ids = RadarReactionHistory::where('decided_at', '<', $cutoff)->orderBy('id')->limit(self::BATCH)->pluck('id');
            $total += $ids->isEmpty() ? 0 : RadarReactionHistory::whereKey($ids)->delete();
        } while ($ids->count() === self::BATCH);
        Log::info('[Radar] Histórico das reações: apagadas as decisões com mais de 12 meses', ['apagadas' => $total]);

        return $total;
    }
}
