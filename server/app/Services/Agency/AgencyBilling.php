<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Models\Company;
use Carbon\CarbonInterface;

/**
 * Faturação da gestão por agências: PAGA QUEM DÁ O ACESSO.
 *  · Uma empresa com subscrição própria ativa continua a pagá-la; a agência não paga por ela.
 *  · Uma empresa cujo acesso depende da agência (sem subscrição própria ativa) conta para a
 *    agência: 15 € por mês, a partir do mês seguinte ao início da relação.
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
}
