<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CmReservationShiftSummary;
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
    public function resolveToken(PingwinLocation $location, ?string $companyToken = null): ?string
    {
        $override = trim((string) $location->cm_token);
        if ($override !== '') {
            return $override;
        }
        $company = trim((string) ($companyToken ?? ''));

        return $company !== '' ? $company : null;
    }

    /** Lojas sincronizáveis: com slug E com token resolvível (loja ou empresa). */
    public function syncableLocations(int $companyId, ?string $companyToken = null)
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
     * Agrega a lista de reservas por turno — SÓ números (o PII fica de fora).
     * Regras (spike): cancelada (status começa por "-") → só cancelled_count++;
     * não-cancelada → reservations_count++ e guests_total += "for"; walk-in
     * (provenance walk in/walk-in/walkin) → também walk_ins_count++.
     */
    public function aggregate(array $reservs): array
    {
        $blank = fn () => ['guests_total' => 0, 'reservations_count' => 0, 'walk_ins_count' => 0, 'cancelled_count' => 0];
        $out = ['lunch' => $blank(), 'dinner' => $blank(), 'other' => $blank()];

        foreach ($reservs as $r) {
            if (! is_array($r)) {
                continue;
            }
            $shift = $this->normalizeShift($r['meal_shift'] ?? null);

            // Cancelada: status a começar por "-" → só conta como cancelada.
            if (str_starts_with(trim((string) ($r['status'] ?? '')), '-')) {
                $out[$shift]['cancelled_count']++;
                continue;
            }

            $out[$shift]['reservations_count']++;
            $out[$shift]['guests_total'] += (int) ($r['for'] ?? 0);

            $prov = str_replace([' ', '-', '_'], '', mb_strtolower((string) ($r['provenance'] ?? '')));
            if ($prov === 'walkin') {
                $out[$shift]['walk_ins_count']++;
            }
        }

        return $out;
    }

    /**
     * Sincroniza UMA loja: busca reservas → agrega → UPSERT do agregado. Devolve
     * SÓ os números por turno (nenhum PII). Persiste apenas turnos com atividade.
     * O token é RESOLVIDO (loja > empresa); $companyToken é o fallback da empresa.
     */
    public function syncLocation(PingwinLocation $location, string $date, ?string $companyToken = null): array
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
                // Uma loja a falhar não parte as outras — regista e continua.
                Log::warning('[CoverManager] sync de loja falhou', [
                    'company_id' => $companyId, 'location_id' => $location->id, 'error' => $e->getMessage(),
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
