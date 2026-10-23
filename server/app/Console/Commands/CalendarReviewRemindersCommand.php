<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Alert;
use App\Services\AlertService;
use App\Services\Billing\ChargeService;
use App\Services\Calendar\PortugalCalendar;
use App\Services\Radar\Calendar\OfficialDates;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Bússola 6: o aviso ao root de "rever a data". Uma vez por mês (no sino da equipa), as datas do
 * calendário cujo review_month é este mês, as por confirmar e os feriados nacionais enquanto não
 * estiverem confirmados na fonte. Corre todos os dias de manhã e só avisa uma vez em cada mês.
 */
class CalendarReviewRemindersCommand extends Command
{
    protected $signature = 'calendar:review-reminders';

    protected $description = 'Bússola: avisa o root das datas do calendário a rever este mês.';

    public function handle(AlertService $alerts): int
    {
        $now = CarbonImmutable::now('Europe/Lisbon');
        $team = ChargeService::teamCompanyId();
        if (! $team) {
            $this->warn('Sem a empresa da equipa: nenhum aviso.');

            return self::SUCCESS;
        }
        $title = 'Rever as datas do calendário (' . $now->locale('pt_PT')->translatedFormat('F \d\e Y') . ')';
        if (Alert::where('company_id', $team)->where('title', $title)->exists()) {
            $this->info('O aviso deste mês já existe.');

            return self::SUCCESS;
        }
        $lines = [];
        foreach (OfficialDates::dueForReview((int) $now->month) as $e) {
            $state = OfficialDates::status($e) === OfficialDates::PENDING ? 'por confirmar' : 'confirmada a ' . CarbonImmutable::parse($e['confirmed_on'])->format('d/m/Y');
            $lines[] = "{$e['title']} ({$state}; {$e['source']['name']}).";
        }
        if (PortugalCalendar::source()['status'] === 'por_confirmar') {
            $lines[] = 'Feriados nacionais (por confirmar; ' . PortugalCalendar::SOURCE_CT['name'] . ').';
        }
        if ($lines === []) {
            $this->info('Nada a rever este mês.');

            return self::SUCCESS;
        }
        $alerts->createSystemAlert($team, 'warning', $title, mb_substr('Confirme na fonte: ' . implode(' ', $lines), 0, 900), 'low', '/admin/radar');
        $this->info('Aviso criado: ' . count($lines) . ' datas.');

        return self::SUCCESS;
    }
}
