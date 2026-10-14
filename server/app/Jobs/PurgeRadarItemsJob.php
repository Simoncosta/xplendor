<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\RadarItem;
use App\Models\RadarRun;
use App\Models\RadarTopic;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Radar: apaga ao fim de 30 dias os itens, as tendências e as recolhas (a regra da noite: dos
 * feeds guarda-se pouco e por pouco tempo). Em lotes, para não prender as tabelas. Tarefa
 * interna (radar-purge-items, 03:20 em Lisboa); o registo só leva contagens.
 */
class PurgeRadarItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const RETENTION_DAYS = 30;

    public const BATCH = 1000;

    public int $tries = 1;
    public int $timeout = 600;

    /** @return array{itens: int, tendencias: int, recolhas: int} */
    public function handle(): array
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $now = now();
        $stats = [
            'itens' => $this->purge(RadarItem::query()->where(fn ($q) => $q->where('fetched_at', '<', $cutoff)->orWhere('expires_at', '<=', $now))),
            'tendencias' => $this->purge(RadarTopic::query()->where(fn ($q) => $q->where('last_seen_at', '<', $cutoff)->orWhere('expires_at', '<=', $now))),
            'recolhas' => $this->purge(RadarRun::query()->where('started_at', '<', $cutoff)),
        ];
        Log::info('[Radar] Apagamento aos 30 dias', $stats);

        return $stats;
    }

    private function purge($query): int
    {
        $total = 0;
        do {
            $ids = (clone $query)->orderBy('id')->limit(self::BATCH)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $total += $query->getModel()->newQuery()->whereKey($ids)->delete();
        } while ($ids->count() === self::BATCH);

        return $total;
    }
}
