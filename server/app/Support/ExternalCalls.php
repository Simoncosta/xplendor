<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;

/**
 * Guarda única das chamadas externas que a plataforma faz sozinha (agendador, observadores e
 * avisos automáticos): Standvirtual, Meta, IA, a revalidação dos sites e os emails automáticos.
 * Segue o interruptor config('scheduler.external_jobs') (SCHEDULE_EXTERNAL_JOBS): em produção
 * está sempre ligado (nada muda); fora dela fica desligado por omissão, para o dev nunca chamar
 * serviços reais sem querer.
 *
 * A empresa vem como parâmetro para, mais tarde, a empresa de demonstração poder ficar sempre
 * de fora (ainda não existe nenhuma marca para a identificar).
 */
final class ExternalCalls
{
    public static function allowed(Company|int|null $company = null): bool
    {
        return (bool) config('scheduler.external_jobs');
    }
}
