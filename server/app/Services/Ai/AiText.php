<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Utilitários de texto dos prompts e das respostas da IA (os mesmos do blog):
 *  · os dados do utilizador e da empresa vão entre delimitadores e nunca os podem "fechar";
 *  · a resposta é JSON e é lida com tolerância (texto à volta do objeto);
 *  · o resultado é limpo (sem HTML nem caracteres de controlo) antes de ser mostrado.
 */
final class AiText
{
    public const DATA_OPEN = '<<<DADOS';
    public const DATA_CLOSE = 'DADOS>>>';

    /** Remove caracteres de controlo e os delimitadores; corta no máximo. */
    public static function clean(string $raw, int $max, bool $keepNewlines = false): string
    {
        $raw = str_replace([self::DATA_OPEN, self::DATA_CLOSE, '<<<', '>>>'], ' ', $raw);
        $raw = $keepNewlines
            ? preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $raw)
            : preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw);
        $raw = $keepNewlines ? preg_replace("/[ \t]+/u", ' ', (string) $raw) : preg_replace('/\s+/u', ' ', (string) $raw);

        return mb_substr(trim((string) $raw), 0, $max);
    }

    public static function wrap(string $text): string
    {
        return self::DATA_OPEN . "\n" . $text . "\n" . self::DATA_CLOSE;
    }

    /** Lista curta para o prompt ("a, b, c"). */
    public static function list(array $items, int $max = 30, int $each = 60): string
    {
        return implode(', ', array_filter(array_map(fn ($i) => self::clean((string) $i, $each), array_slice($items, 0, $max))));
    }

    public static function decodeJson(string $raw): array
    {
        $decoded = json_decode(trim($raw), true);
        if (! is_array($decoded)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            }
        }
        if (! is_array($decoded)) {
            throw new \RuntimeException('A IA devolveu um JSON inválido.');
        }

        return $decoded;
    }

    /** Texto simples de uma resposta (sem HTML), cortado no máximo. */
    public static function plain(mixed $value, int $max, bool $keepNewlines = false): string
    {
        return self::clean(strip_tags(html_entity_decode((string) (is_scalar($value) ? $value : ''), ENT_QUOTES | ENT_HTML5)), $max, $keepNewlines);
    }

    /** Lista de textos simples, sem repetidos nem vazios. */
    public static function plainList(mixed $value, int $maxItems, int $each): array
    {
        $items = array_map(fn ($v) => self::plain($v, $each), array_slice(is_array($value) ? $value : [], 0, $maxItems));

        return array_values(array_unique(array_filter($items, fn ($v) => $v !== '')));
    }

    /** Hashtags normalizadas: uma palavra, sempre com "#", sem repetidas. */
    public static function hashtags(mixed $value, int $maxItems = 30): array
    {
        $out = [];
        foreach (array_slice(is_array($value) ? $value : [], 0, $maxItems) as $h) {
            $tag = preg_replace('/[\s#]+/u', '', self::plain($h, 60));
            if ($tag !== '' && ! in_array('#' . $tag, $out, true)) {
                $out[] = '#' . $tag;
            }
        }

        return $out;
    }
}
