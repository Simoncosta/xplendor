<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Access\Access;
use App\Models\User;
use App\Support\Text\MoneyText;

/**
 * Bússola sem Finanças: quem não tem a permissão de ver as Finanças (financas.ver) vê a Bússola
 * sem os valores em euros. O backend NÃO os envia (o desfoque só no ecrã deixava-os legíveis
 * nas ferramentas do browser):
 *  · os campos em cêntimos (`*_cents`) vão a null;
 *  · um número em euros (format "eur") e uma barra com valores em euros vão com value null e
 *    hidden true (o ecrã mostra um número fictício desfocado);
 *  · os textos (frases dos sinais, jogadas) perdem os valores em euros.
 * Ficam as jogadas, as quantidades, os mais e menos vendidos, as horas e os dias fortes e as
 * variações em percentagem. A resposta diz se os valores vieram: financial.visible.
 */
final class CompassFinancials
{
    public const NOTE = 'Sem acesso aos valores financeiros';

    public static function visibleTo(?User $user, int $companyId): bool
    {
        return $user !== null && app(Access::class)->can($user, $companyId, 'financas.ver')->allowed;
    }

    /** O payload para esta pessoa, com o indicador financial. */
    public static function forUser(array $payload, ?User $user, int $companyId): array
    {
        if (self::visibleTo($user, $companyId)) {
            return $payload + ['financial' => ['visible' => true, 'note' => null]];
        }

        return self::redact($payload) + ['financial' => ['visible' => false, 'note' => self::NOTE]];
    }

    public static function redact(array $data): array
    {
        $isEuroNumber = ($data['format'] ?? null) === 'eur' && array_key_exists('value', $data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_string($key) && str_ends_with($key, '_cents')) {
                $data[$key] = null;
            } elseif ($key === 'value' && ($isEuroNumber || (is_string($value) && MoneyText::hasMoney($value)))) {
                $data[$key] = null;
                $data['hidden'] = true;
            } elseif (is_string($value)) {
                $data[$key] = MoneyText::redact($value);
            }
        }

        return $data;
    }
}
