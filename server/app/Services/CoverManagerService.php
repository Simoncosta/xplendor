<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CmReservationChannelDaily;
use App\Models\CmReservationHourly;
use App\Models\CmReservationLeadtimeDaily;
use App\Models\CmReservationShiftSummary;
use App\Models\CmReservationStatusDaily;
use App\Models\CompanyIntegration;
use App\Models\PingwinLocation;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — CoverManager Etapa 1: buscar reservas → AGREGAR EM MEMÓRIA por turno
 * → guardar SÓ os números → DESCARTAR o PII. ⚠️ RGPD: nome/email/telefone/raw
 * NUNCA tocam na BD. Só guardamos guests_total/reservations/walk-ins/cancelled.
 */
class CoverManagerService
{
    public const SHIFTS = ['lunch', 'dinner', 'other'];
    private const PLATFORM = 'covermanager';

    public function __construct(private CoverManagerClient $client) {}

    /** Token do CoverManager AO NÍVEL DA EMPRESA (fallback). Cifrado; o cast decifra. */
    public function companyToken(int $companyId): ?string
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->where('status', '!=', 'revoked')
            ->first();

        $token = $integration ? trim((string) $integration->access_token) : '';

        return $token !== '' ? $token : null;
    }

    /** Empresa TEM integração CoverManager? (token de empresa configurado). */
    public function companyHasIntegration(int $companyId): bool
    {
        return $this->companyToken($companyId) !== null;
    }

    /**
     * ⚠️ RESOLUÇÃO do token: a LOJA tem prioridade (override); a EMPRESA é o
     * fallback. Devolve null se nenhum existir.
     */
    public function resolveToken(PingwinLocation $location, #[\SensitiveParameter] ?string $companyToken = null): ?string
    {
        $override = trim((string) $location->cm_token);
        if ($override !== '') {
            return $override;
        }
        $company = trim((string) ($companyToken ?? ''));

        return $company !== '' ? $company : null;
    }

    /** Lojas sincronizáveis: com slug E com token resolvível (loja ou empresa). */
    public function syncableLocations(int $companyId, #[\SensitiveParameter] ?string $companyToken = null)
    {
        return PingwinLocation::where('company_id', $companyId)
            ->whereNotNull('cm_slug')->where('cm_slug', '!=', '')
            ->get()
            ->filter(fn (PingwinLocation $l) => $this->resolveToken($l, $companyToken) !== null)
            ->values();
    }

    /** meal_shift → lunch|dinner|other (normalização do spike). */
    public function normalizeShift(?string $shift): string
    {
        $v = mb_strtolower(trim((string) $shift));

        if (in_array($v, ['lunch', 'almoço', 'almoco'], true)) {
            return 'lunch';
        }
        if (in_array($v, ['dinner', 'jantar'], true)) {
            return 'dinner';
        }

        return 'other';
    }

    /**
     * Código de estado do CoverManager que é uma falta (o cliente não apareceu). Mapa usado
     * no projeto yukotavern (lib/metrics.php): "3" confirmada, "5" concluída, "-2" anulada,
     * "-3" falta. Os outros códigos começados por "-" contam como anulação; os restantes,
     * como reserva válida (como até aqui). Por confirmar na sessão acompanhada, com a
     * contagem por código guardada em cm_reservation_status_daily.
     */
    public const STATUS_NO_SHOW = '-3';

    /** Escalões de antecedência (dias entre a criação da reserva e o dia da reserva). */
    public const LEAD_BUCKETS = ['same_day' => [0, 0], 'd1_2' => [1, 2], 'd3_7' => [3, 7], 'd8_30' => [8, 30], 'd31_plus' => [31, PHP_INT_MAX]];

    /** cancelled | no_show | valid, a partir do código de estado. */
    public function statusKind($status): string
    {
        $code = trim((string) ($status ?? ''));
        if ($code === self::STATUS_NO_SHOW) {
            return 'no_show';
        }

        return str_starts_with($code, '-') ? 'cancelled' : 'valid';
    }

    private function isWalkIn(array $r): bool
    {
        return str_replace([' ', '-', '_'], '', mb_strtolower((string) ($r['provenance'] ?? ''))) === 'walkin';
    }

    /**
     * Agrega a lista de reservas por turno — SÓ números (o PII fica de fora).
     * Regras: anulada (status começa por "-", exceto a falta "-3") → só cancelled_count++;
     * falta ("-3") → só no_show_count++; as outras → reservations_count++ e guests_total
     * += "for"; walk-in (provenance walk in/walk-in/walkin) → também walk_ins_count++.
     */
    public function aggregate(array $reservs): array
    {
        $blank = fn () => ['guests_total' => 0, 'reservations_count' => 0, 'walk_ins_count' => 0, 'cancelled_count' => 0, 'no_show_count' => 0];
        $out = ['lunch' => $blank(), 'dinner' => $blank(), 'other' => $blank()];

        foreach ($reservs as $r) {
            if (! is_array($r)) {
                continue;
            }
            $shift = $this->normalizeShift($r['meal_shift'] ?? null);

            $kind = $this->statusKind($r['status'] ?? null);
            if ($kind === 'cancelled') {
                $out[$shift]['cancelled_count']++;
                continue;
            }
            if ($kind === 'no_show') {
                $out[$shift]['no_show_count']++;
                continue;
            }

            $out[$shift]['reservations_count']++;
            $out[$shift]['guests_total'] += (int) ($r['for'] ?? 0);

            if ($this->isWalkIn($r)) {
                $out[$shift]['walk_ins_count']++;
            }
        }

        return $out;
    }

    /**
     * F2: agregados por hora (hora da reserva), por canal (provenance), por antecedência e por
     * código de estado — SÓ números. Hora, canal e antecedência contam só as reservas válidas
     * (sem anuladas nem faltas); a antecedência deixa de fora os walk-ins (não reservam). Os
     * códigos de estado contam todas as reservas.
     */
    public function details(array $reservs, string $businessDate): array
    {
        $hourly = [];
        $channels = [];
        $lead = [];
        $statuses = [];
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate) ?: null;

        foreach ($reservs as $r) {
            if (! is_array($r)) {
                continue;
            }
            $code = mb_substr(trim((string) ($r['status'] ?? '')), 0, 12);
            $statuses[$code === '' ? '(vazio)' : $code] = ($statuses[$code === '' ? '(vazio)' : $code] ?? 0) + 1;
            if ($this->statusKind($r['status'] ?? null) !== 'valid') {
                continue;
            }
            $guests = (int) ($r['for'] ?? 0);
            $walkIn = $this->isWalkIn($r);

            if (preg_match('/^(\d{1,2}):\d{2}/', trim((string) ($r['time'] ?? '')), $m) && (int) $m[1] <= 23) {
                $h = (int) $m[1];
                $hourly[$h] ??= ['reservations_count' => 0, 'guests_total' => 0, 'walk_ins_count' => 0];
                $hourly[$h]['reservations_count']++;
                $hourly[$h]['guests_total'] += $guests;
                $hourly[$h]['walk_ins_count'] += $walkIn ? 1 : 0;
            }

            $channel = $walkIn ? 'walk in' : mb_substr(trim((string) preg_replace('/\s+/', ' ', mb_strtolower((string) ($r['provenance'] ?? '')))), 0, 40);
            $channel = $channel === '' ? 'sem canal' : $channel;
            $channels[$channel] ??= ['reservations_count' => 0, 'guests_total' => 0];
            $channels[$channel]['reservations_count']++;
            $channels[$channel]['guests_total'] += $guests;

            $added = \DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim((string) ($r['date_add'] ?? '')), 0, 10)) ?: null;
            if (! $walkIn && $day && $added && $added <= $day) {
                $days = (int) $added->diff($day)->days;
                foreach (self::LEAD_BUCKETS as $bucket => [$min, $max]) {
                    if ($days >= $min && $days <= $max) {
                        $lead[$bucket] ??= ['reservations_count' => 0, 'guests_total' => 0];
                        $lead[$bucket]['reservations_count']++;
                        $lead[$bucket]['guests_total'] += $guests;
                        break;
                    }
                }
            }
        }
        ksort($hourly);

        return ['hourly' => $hourly, 'channels' => $channels, 'lead' => $lead, 'statuses' => $statuses];
    }

    /** Substitui os agregados F2 de uma loja × dia (só com o interruptor da empresa ligado). */
    private function storeDetails(PingwinLocation $location, string $date, array $details): void
    {
        $now = now();
        $base = ['company_id' => $location->company_id, 'location_id' => $location->id, 'business_date' => $date, 'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        \Illuminate\Support\Facades\DB::transaction(function () use ($location, $date, $details, $base) {
            foreach ([CmReservationHourly::class, CmReservationChannelDaily::class, CmReservationLeadtimeDaily::class, CmReservationStatusDaily::class] as $model) {
                $model::where('location_id', $location->id)->where('business_date', $date)->delete();
            }
            foreach ($details['hourly'] as $hour => $v) {
                CmReservationHourly::insert($base + ['hour' => $hour] + $v);
            }
            foreach ($details['channels'] as $channel => $v) {
                CmReservationChannelDaily::insert($base + ['channel' => $channel] + $v);
            }
            foreach ($details['lead'] as $bucket => $v) {
                CmReservationLeadtimeDaily::insert($base + ['bucket' => $bucket] + $v);
            }
            foreach ($details['statuses'] as $code => $n) {
                CmReservationStatusDaily::insert($base + ['status_code' => (string) $code, 'reservations_count' => $n]);
            }
        });
    }

    /**
     * Sincroniza UMA loja: busca reservas → agrega → UPSERT do agregado. Devolve
     * SÓ os números por turno (nenhum PII). Persiste apenas turnos com atividade.
     * O token é RESOLVIDO (loja > empresa); $companyToken é o fallback da empresa.
     */
    public function syncLocation(PingwinLocation $location, string $date, #[\SensitiveParameter] ?string $companyToken = null): array
    {
        $token = $this->resolveToken($location, $companyToken);
        if ($token === null) {
            throw new \RuntimeException('Sem token CoverManager (loja nem empresa).');
        }

        $reservs = $this->client->getReservations(
            $token, // token resolvido (loja > empresa)
            (string) $location->cm_slug,
            $date,
            $location->cm_base_url,
        );

        $agg = $this->aggregate($reservs);
        $now = now();

        foreach ($agg as $shift => $counts) {
            // Turno sem qualquer atividade → não cria linha (evita ruído).
            if (array_sum($counts) === 0) {
                continue;
            }
            CmReservationShiftSummary::updateOrCreate(
                ['location_id' => $location->id, 'business_date' => $date, 'shift' => $shift],
                array_merge($counts, ['company_id' => $location->company_id, 'synced_at' => $now]),
            );
        }

        // F2: com o interruptor da empresa ligado, guarda também os agregados por hora,
        // canal, antecedência e código de estado (tirados desta mesma leitura).
        if (PingwinItemSalesService::isEnabled((int) $location->company_id)) {
            $this->storeDetails($location, $date, $this->details($reservs, $date));
        }

        // ⚠️ Devolve só números — o $reservs (com PII) fica em memória e é descartado.
        return $agg;
    }

    /**
     * Sincroniza TODAS as lojas da empresa com credencial CoverManager. Uma loja
     * a falhar NÃO aborta as outras. Devolve o resumo (números + falhas).
     */
    public function sync(int $companyId, string $date): array
    {
        $companyToken = $this->companyToken($companyId);
        $locations = $this->syncableLocations($companyId, $companyToken);

        $results = [];
        $failed = [];

        foreach ($locations as $location) {
            try {
                $results[] = [
                    'location_id' => $location->id,
                    'display_name' => $location->display_name,
                    'shifts' => $this->syncLocation($location, $date, $companyToken),
                ];
            } catch (\Throwable $e) {
                // Uma loja a falhar não parte as outras — regista e continua. O cliente já
                // mascara o token; volta a mascarar aqui para qualquer outra origem do erro.
                Log::warning('[CoverManager] sync de loja falhou', [
                    'company_id' => $companyId, 'location_id' => $location->id,
                    'error' => CoverManagerClient::mask($e->getMessage(), $this->resolveToken($location, $companyToken)),
                ]);
                $failed[] = $location->display_name ?: (string) $location->id;
            }
        }

        return [
            'date' => $date,
            'locations_total' => $locations->count(),
            'synced' => count($results),
            'failed' => $failed,
            'results' => $results,
        ];
    }
}
