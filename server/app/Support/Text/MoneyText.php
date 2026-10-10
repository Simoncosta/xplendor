<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * Retira os valores em euros de um texto, para quem não os pode ver (a faturação da XPLENDOR
 * nas mensagens dos pedidos de suporte; os valores financeiros nos textos da Bússola).
 * Apanha "1.234,56 €", "12€", "€ 12", "12 euros" e "12 EUR"; as percentagens e as quantidades
 * ficam como estão.
 */
final class MoneyText
{
    public const PLACEHOLDER = '(valor reservado)';

    private const NUMBER = '\d{1,3}(?:[.\s\x{00A0}\x{202F}]\d{3})*(?:,\d+)?|\d+(?:[.,]\d+)?';

    public static function redact(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $n = self::NUMBER;
        $text = (string) preg_replace("/(?:€|EUR)\s?(?:{$n})(?:\s?(?:mil|k))?/u", self::PLACEHOLDER, $text);

        return (string) preg_replace("/(?<![\w,.])(?:{$n})(?:\s?(?:mil|k))?\s?(?:€|euros?\b|EUR\b)/iu", self::PLACEHOLDER, $text);
    }

    public static function hasMoney(?string $text): bool
    {
        return $text !== null && self::redact($text) !== $text;
    }
}
