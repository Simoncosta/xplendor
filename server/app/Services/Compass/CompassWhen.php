<?php

declare(strict_types=1);

namespace App\Services\Compass;

use Carbon\CarbonImmutable;

/**
 * O "Quando" de uma jogada, calculado na leitura: nunca uma data passada ($publish anterior a
 * hoje passa a hoje); hoje diz "Publicar hoje".
 */
final class CompassWhen
{
    public const WEEKDAY = [1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo'];

    /** @return array{date: string, is_today: bool, label: string, target: ?string} */
    public static function present(CarbonImmutable $publish, CarbonImmutable $today, ?CarbonImmutable $target = null, string $verb = 'Publicar'): array
    {
        if ($publish->lt($today)) {
            $publish = $today;
        }
        $isToday = $publish->isSameDay($today);

        return [
            'date' => $publish->toDateString(),
            'is_today' => $isToday,
            'label' => $isToday ? "{$verb} hoje" : "{$verb} " . self::onDay($publish),
            'target' => $target ? ($target->dayOfWeekIso >= 6 ? 'para o ' : 'para a ') . self::WEEKDAY[$target->dayOfWeekIso] . ', ' . $target->format('d/m') : null,
        ];
    }

    /** "na terça-feira, 13/10" / "no sábado, 17/10". */
    public static function onDay(CarbonImmutable $day): string
    {
        return ($day->dayOfWeekIso >= 6 ? 'no ' : 'na ') . self::WEEKDAY[$day->dayOfWeekIso] . ', ' . $day->format('d/m');
    }
}
