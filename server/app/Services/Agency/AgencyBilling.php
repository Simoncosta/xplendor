<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Models\Company;
use App\Models\CompanyManagement;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Faturação da gestão por agências: PAGA QUEM DÁ O ACESSO.
 *  · Uma empresa com subscrição própria ativa continua a pagá-la; a agência não paga por ela.
 *  · Uma empresa cujo acesso depende da agência (sem subscrição própria ativa) conta para a
 *    agência: 15 € por mês, a partir do mês seguinte ao início da relação.
 *  · A contagem de um mês: as relações ativas que começaram antes desse mês, de empresas sem
 *    subscrição própria ativa (se uma empresa deixar de pagar e continuar gerida, passa a
 *    contar). O mês atual usa o registo do dia 1 quando existe; o seguinte é calculado agora.
 */
class AgencyBilling
{
    public const MONTHLY_FEE = 15;

    private const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    /** A situação de uma empresa gerida (para o aviso ao root e os ecrãs). */
    public static function situation(Company $company, ?CarbonInterface $since = null): array
    {
        $paysOwn = $company->paysOwnSubscription();
        $from = ($since ?? now())->copy()->startOfMonth()->addMonth();

        return [
            'pays_own' => $paysOwn,
            'counts_for_agency' => ! $paysOwn,
            'from_month' => $paysOwn ? null : $from->format('Y-m'),
            'monthly_fee' => self::MONTHLY_FEE,
            'label' => $paysOwn
                ? 'A empresa tem subscrição própria ativa: continua a pagá-la e não conta para a agência.'
                : sprintf('A empresa não tem subscrição própria ativa: passa a contar para a agência (%d € por mês, a partir de %s).', self::MONTHLY_FEE, self::monthLabel($from)),
        ];
    }

    public static function monthLabel(CarbonInterface $d): string
    {
        return self::MONTHS[$d->month - 1] . ' de ' . $d->year;
    }

    /** O início de uma relação (a resposta ou o pedido). */
    public static function startOf(CompanyManagement $m): CarbonImmutable
    {
        return CarbonImmutable::parse($m->responded_at ?? $m->requested_at ?? $m->created_at);
    }

    /**
     * As relações ativas de uma agência, com o que cada empresa conta no mês $month (AAAA-MM).
     *
     * @return Collection<int, array{company: Company, since: CarbonImmutable, pays_own: bool, counts: bool}>
     */
    public static function rows(Company $agency, string $month): Collection
    {
        $monthStart = CarbonImmutable::parse("{$month}-01")->startOfMonth();

        return CompanyManagement::active()->where('agency_company_id', $agency->id)->with('managed')->get()
            ->filter(fn (CompanyManagement $m) => $m->managed !== null)
            ->map(function (CompanyManagement $m) use ($monthStart) {
                $since = self::startOf($m);
                $paysOwn = $m->managed->paysOwnSubscription();

                return ['company' => $m->managed, 'since' => $since, 'pays_own' => $paysOwn,
                    'counts' => ! $paysOwn && $m->managed->archived_at === null && $since->lt($monthStart)];
            })->values();
    }

    /** @return int[] as empresas que contam para a agência no mês */
    public static function countingIds(Company $agency, string $month): array
    {
        return self::rows($agency, $month)->where('counts', true)->map(fn ($r) => $r['company']->id)->values()->all();
    }

    /** O registo do dia 1 (cria ou atualiza). */
    public static function snapshot(Company $agency, string $month): int
    {
        $ids = self::countingIds($agency, $month);
        DB::table('agency_billing_counts')->updateOrInsert(
            ['agency_company_id' => $agency->id, 'month' => $month],
            ['companies_count' => count($ids), 'company_ids' => json_encode($ids), 'monthly_fee' => self::MONTHLY_FEE, 'computed_at' => now()],
        );

        return count($ids);
    }

    /** Contagem do mês atual e do seguinte, com a lista (para o root e o admin da agência). */
    public static function summary(Company $agency): array
    {
        $now = CarbonImmutable::now('Europe/Lisbon');
        $current = $now->format('Y-m');
        $next = $now->startOfMonth()->addMonth()->format('Y-m');
        $snap = DB::table('agency_billing_counts')->where('agency_company_id', $agency->id)->where('month', $current)->first();
        $currentIds = $snap ? json_decode((string) $snap->company_ids, true) : self::countingIds($agency, $current);
        $nextRows = self::rows($agency, $next);

        return [
            'monthly_fee' => self::MONTHLY_FEE,
            'current' => ['month' => $current, 'count' => count($currentIds), 'from_snapshot' => (bool) $snap],
            'next' => ['month' => $next, 'count' => $nextRows->where('counts', true)->count()],
            'companies' => $nextRows->map(fn ($r) => [
                'id' => $r['company']->id, 'name' => (string) ($r['company']->trade_name ?: $r['company']->fiscal_name),
                'since' => $r['since']->toDateString(), 'pays_own' => $r['pays_own'],
                'counts_current' => in_array($r['company']->id, $currentIds, true), 'counts_next' => $r['counts'],
            ])->values()->all(),
        ];
    }
}
