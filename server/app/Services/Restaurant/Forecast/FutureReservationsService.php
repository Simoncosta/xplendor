<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Forecast;

use App\Models\CmReservationSnapshot;
use App\Models\PingwinLocation;
use App\Services\CoverManagerClient;
use App\Services\CoverManagerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Fatia 6a: as reservas dos próximos 30 dias, por loja, lidas no CoverManager (SÓ LEITURA, um pedido
 * por loja). Guarda uma fotografia por leitura e por dia, só com números (mesas e pessoas por estado
 * e as mesas por canal): o nome, o telefone e o email das reservas nunca se guardam.
 */
class FutureReservationsService
{
    public const DAYS = 30;

    public function __construct(private readonly CoverManagerService $cover, private readonly CoverManagerClient $client) {}

    /** @return array{locations:int, days:int, failed:list<string>} */
    public function sync(int $companyId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('Europe/Lisbon');
        $from = $now->startOfDay();
        $to = $from->addDays(self::DAYS);
        $companyToken = $this->cover->companyToken($companyId);
        $out = ['locations' => 0, 'days' => 0, 'failed' => []];
        foreach ($this->cover->syncableLocations($companyId, $companyToken) as $l) {
            try {
                $list = $this->client->getReservationsRange((string) $this->cover->resolveToken($l, $companyToken), (string) $l->cm_slug, $from->toDateString(), $to->toDateString());
            } catch (\Throwable $e) {
                Log::warning('[Reservas futuras] leitura falhou', ['location_id' => $l->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
                $out['failed'][] = $l->display_name ?: (string) $l->id;
                continue;
            }
            $out['locations']++;
            $out['days'] += $this->store($l, $list, $from, $to, $now);
        }

        return $out;
    }

    /** Agrega por dia e guarda a fotografia (todos os dias do intervalo, também os sem reservas). */
    public function store(PingwinLocation $l, array $list, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $readAt): int
    {
        $byDay = [];
        foreach ($list as $r) {
            if (! is_array($r) || empty($r['date'])) {
                continue;
            }
            $d = substr((string) $r['date'], 0, 10);
            $kind = $this->cover->statusKind($r['status'] ?? null);
            $guests = (int) ($r['for'] ?? 0);
            $byDay[$d]['status'][$kind]['tables'] = ($byDay[$d]['status'][$kind]['tables'] ?? 0) + 1;
            $byDay[$d]['status'][$kind]['guests'] = ($byDay[$d]['status'][$kind]['guests'] ?? 0) + $guests;
            if ($kind === 'valid') {
                $ch = trim((string) ($r['provenance'] ?? '')) ?: 'sem canal';
                $byDay[$d]['channel'][$ch] = ($byDay[$d]['channel'][$ch] ?? 0) + 1;
            }
        }
        $rows = [];
        $stamp = now();
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $x = $byDay[$key] ?? [];
            $rows[] = [
                'company_id' => $l->company_id, 'location_id' => $l->id, 'business_date' => $key, 'read_at' => $readAt->utc()->format('Y-m-d H:i:s'),
                'lead_days' => (int) $from->diffInDays($d), 'tables_count' => (int) ($x['status']['valid']['tables'] ?? 0),
                'guests_total' => (int) ($x['status']['valid']['guests'] ?? 0),
                'by_status' => json_encode($x['status'] ?? (object) []), 'by_channel' => json_encode($x['channel'] ?? (object) []),
                'created_at' => $stamp, 'updated_at' => $stamp,
            ];
        }
        CmReservationSnapshot::upsert($rows, ['location_id', 'business_date', 'read_at'], ['tables_count', 'guests_total', 'by_status', 'by_channel', 'lead_days', 'updated_at']);

        return count($rows);
    }
}
