<?php

declare(strict_types=1);

namespace App\Services\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Saúde da XPLENDOR: base de dados, Redis, antivírus, disco e os sinais de vida do scheduler
 * (batimento de minuto a minuto) e do worker (um job de minuto a minuto que só bate quando o
 * worker o corre). Usado pela rota pública /api/health (só 200 ou 503), pela consola do root e
 * pelo health:watch (que avisa).
 */
class SystemHealth
{
    public const TABLE = 'system_health_checks';

    public const LABELS = [
        'database' => 'Base de dados',
        'redis' => 'Redis (fila e cache)',
        'antivirus' => 'Antivírus (ClamAV)',
        'disk' => 'Disco do servidor',
        'scheduler' => 'Agendador (scheduler)',
        'worker' => 'Fila (worker)',
    ];

    public function __construct(private readonly HealthProbes $probes) {}

    /** Sinal de vida (scheduler ou worker). */
    public static function beat(string $name): void
    {
        $now = now();
        DB::table(self::TABLE)->upsert([['name' => $name, 'last_ok_at' => $now, 'updated_at' => $now]], ['name'], ['last_ok_at', 'updated_at']);
    }

    /**
     * Todas as verificações, agora.
     *
     * @return array<string, array{ok: bool, skipped: bool, detail: ?string}>
     */
    public function run(): array
    {
        $out = [];
        $database = $this->probes->database();
        $out['database'] = self::result($database);
        $out['redis'] = self::result($this->probes->redis());
        $out['antivirus'] = self::result($this->probes->antivirus());

        $percent = $this->probes->diskPercent();
        $limit = (float) config('health.disk_alert_percent', 90);
        $out['disk'] = $percent === null ? self::result(HealthProbes::SKIPPED)
            : self::result($percent >= $limit ? "Disco a {$percent}% (limite de " . (int) $limit . '%).' : null);

        $beats = $database === null ? DB::table(self::TABLE)->whereIn('name', ['scheduler', 'worker'])->pluck('last_ok_at', 'name') : collect();
        foreach (['scheduler' => 'health.scheduler_max_minutes', 'worker' => 'health.worker_max_minutes'] as $name => $key) {
            $out[$name] = self::result($database !== null ? 'Sem base de dados para ler o sinal de vida.'
                : self::staleness($beats[$name] ?? null, (int) config($key)));
        }

        return $out;
    }

    /** @param array<string, array{ok: bool}> $results */
    public static function healthy(array $results): bool
    {
        return collect($results)->every(fn ($r) => $r['ok']);
    }

    private static function staleness(?string $lastBeat, int $maxMinutes): ?string
    {
        if ($lastBeat === null) {
            return 'Ainda não deu sinal de vida.';
        }
        $at = CarbonImmutable::parse($lastBeat);
        if ($at->gte(now()->subMinutes($maxMinutes))) {
            return null;
        }

        return 'Sem sinal de vida desde ' . $at->setTimezone('Europe/Lisbon')->format('d/m/Y H:i') . '.';
    }

    /** @return array{ok: bool, skipped: bool, detail: ?string} */
    private static function result(?string $problem): array
    {
        if ($problem === HealthProbes::SKIPPED) {
            return ['ok' => true, 'skipped' => true, 'detail' => 'Não usado nesta instalação.'];
        }

        return ['ok' => $problem === null, 'skipped' => false, 'detail' => $problem];
    }
}
