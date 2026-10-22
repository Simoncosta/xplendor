<?php

declare(strict_types=1);

namespace App\Services\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A vigilância de 5 em 5 minutos (health:watch): corre as verificações, guarda o estado de cada uma
 * e avisa o root UMA vez quando uma falha dura mais de health.alert_after_minutes, e outra vez
 * quando volta. Se o scheduler parar, esta vigilância também para: quem avisa é o serviço externo
 * que abre /api/health (503).
 */
class HealthWatch
{
    private const DATABASE_ALERTED = 'health:database-alerted';

    public function __construct(private readonly SystemHealth $health, private readonly HealthNotifier $notifier) {}

    /** @return array{healthy: bool, alerted: string[], recovered: string[]} */
    public function watch(): array
    {
        $results = $this->health->run();
        $out = ['healthy' => SystemHealth::healthy($results), 'alerted' => [], 'recovered' => []];

        if (! $results['database']['ok']) {
            // Sem base de dados não há estado guardado: aviso logo, no máximo uma vez por hora (se a cache responder).
            Log::error('[Saúde] A base de dados não responde');
            if (self::claim()) {
                $this->notifier->down(['database' => $results['database']]);
                $out['alerted'][] = 'database';
            }

            return $out;
        }
        if (self::release()) {
            $out['recovered'][] = 'database';
        }

        $now = CarbonImmutable::now();
        $alertBefore = $now->subMinutes((int) config('health.alert_after_minutes', 10));
        $rows = DB::table(SystemHealth::TABLE)->get()->keyBy('name');
        $down = [];
        foreach ($results as $name => $r) {
            if ($r['skipped'] || $name === 'database') {
                continue;
            }
            $row = $rows[$name] ?? null;
            if ($r['ok']) {
                if ($row?->alerted_at) {
                    $out['recovered'][] = $name;
                }
                $values = ['failing_since' => null, 'alerted_at' => null, 'detail' => null, 'updated_at' => $now];
                if (! in_array($name, ['scheduler', 'worker'], true)) {
                    $values['last_ok_at'] = $now;
                }
                DB::table(SystemHealth::TABLE)->updateOrInsert(['name' => $name], $values);

                continue;
            }
            $since = $row?->failing_since ? CarbonImmutable::parse($row->failing_since) : $now;
            $alert = ! $row?->alerted_at && $since->lte($alertBefore);
            DB::table(SystemHealth::TABLE)->updateOrInsert(['name' => $name], [
                'failing_since' => $since, 'detail' => mb_substr((string) $r['detail'], 0, 255), 'updated_at' => $now,
                'alerted_at' => $alert ? $now : $row?->alerted_at,
            ]);
            if ($alert) {
                $down[$name] = $r;
            }
        }

        if ($down !== []) {
            Log::warning('[Saúde] Falha avisada', ['checks' => array_keys($down)]);
            $this->notifier->down($down);
            $out['alerted'] = array_keys($down);
        }
        if ($out['recovered'] !== []) {
            $this->notifier->recovered($out['recovered']);
        }

        return $out;
    }

    private static function claim(): bool
    {
        try {
            return Cache::add(self::DATABASE_ALERTED, 1, now()->addHour());
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function release(): bool
    {
        try {
            return (bool) Cache::pull(self::DATABASE_ALERTED);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
