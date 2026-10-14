<?php

declare(strict_types=1);

namespace App\Services\Radar\Filter;

use App\Services\Radar\RadarText;

/**
 * Radar: nomes de pessoas por heurística de maiúsculas (DESENHO.md, secção 5), sem IA.
 *  · Num título com maiúsculas (jornais): duas ou mais palavras seguidas com maiúscula inicial,
 *    sem contar a que abre a frase, que não estão na lista de lugares, marcas, clubes e
 *    instituições (Lexicon::NOT_PERSON). "da", "de", "do"... ligam as palavras de um nome.
 *  · Numa consulta do Google Trends (sempre em minúsculas): a consulta aparece com maiúsculas
 *    nas notícias relacionadas que o próprio feed traz ("rui silva" → "Rui Silva marca...").
 * A heurística prefere apanhar a mais: os falsos positivos ficam à vista no painel do root.
 */
final class PersonNameDetector
{
    public static function inTitle(string $title): bool
    {
        if (! preg_match('/\p{Ll}/u', $title)) {
            return false; // título todo em maiúsculas: as maiúsculas não dizem nada
        }
        foreach (self::sequences(self::tokens($title)) as $seq) {
            $caps = array_values(array_filter($seq, fn ($t) => ! $t['connector']));
            $counted = count($caps) - ($caps[0]['initial'] ? 1 : 0);
            if ($counted >= 2 && ! self::isNotPerson(array_column($seq, 'raw'))) {
                return true;
            }
        }

        return false;
    }

    /** A consulta do Trends (2 a 5 palavras) escrita com maiúsculas numa das notícias relacionadas. */
    public static function trendQueryIsName(string $query, array $relatedTitles): bool
    {
        $words = array_values(array_filter(explode(' ', RadarText::normalize($query))));
        $n = count($words);
        if ($n < 2 || $n > 5 || count(array_diff($words, Lexicon::NAME_CONNECTORS)) < 2 || self::isNotPerson($words)) {
            return false;
        }
        foreach ($relatedTitles as $title) {
            $title = RadarText::cleanTitle((string) $title);
            if (! preg_match('/\p{Ll}/u', $title)) {
                continue;
            }
            $tokens = self::tokens($title);
            $norm = array_map(fn ($t) => RadarText::normalize($t['raw']), $tokens);
            for ($i = 0; $i + $n <= count($tokens); $i++) {
                if (array_slice($norm, $i, $n) !== $words) {
                    continue;
                }
                $caps = 0;
                $ok = true;
                foreach (array_slice($tokens, $i, $n) as $j => $t) {
                    if (in_array($words[$j], Lexicon::NAME_CONNECTORS, true)) {
                        continue;
                    }
                    if (! self::isCapitalized($t['raw'])) {
                        $ok = false;
                        break;
                    }
                    if (! ($j === 0 && $t['initial'])) {
                        $caps++;
                    }
                }
                if ($ok && $caps >= 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Uma etiqueta (categoria do feed) que é só um nome de pessoa ("Cristiano Ronaldo"): não se guarda. */
    public static function isNameLabel(string $label): bool
    {
        $tokens = self::tokens($label);
        $words = array_values(array_filter($tokens, fn ($t) => ! in_array(mb_strtolower($t['raw']), Lexicon::NAME_CONNECTORS, true)));
        if (count($words) < 2 || count($words) > 4) {
            return false;
        }
        foreach ($words as $t) {
            if (! self::isCapitalized($t['raw'])) {
                return false;
            }
        }

        return ! self::isNotPerson(array_column($tokens, 'raw'));
    }

    /**
     * As palavras do texto, com o que diz a pontuação: "initial" quando abre uma frase (início,
     * depois de ". ", ": ", "? ", de um separador " - " ou de umas aspas a abrir) e "break" quando
     * a pontuação a seguir corta a sequência (vírgula, ponto, dois pontos, aspas a fechar).
     *
     * @return array<int, array{raw: string, initial: bool, break: bool}>
     */
    private static function tokens(string $text): array
    {
        $text = preg_replace('/\s+[-–—|]\s+/u', '. ', $text) ?? $text;
        $out = [];
        $initial = true;
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            $core = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $part) ?? '';
            $endsSentence = (bool) preg_match('/[.!?:;][\'"”»’)\]]*$/u', $part);
            if ($core === '') {
                $initial = $initial || $endsSentence;
                continue;
            }
            $out[] = [
                'raw' => $core,
                'initial' => $initial || (bool) preg_match('/^[«“"\'‘(\[]/u', $part),
                'break' => (bool) preg_match('/[^\p{L}\p{N}]$/u', $part),
            ];
            $initial = $endsSentence;
        }

        return $out;
    }

    /**
     * As sequências de palavras com maiúscula (com os conectores entre elas).
     *
     * @return array<int, array<int, array{raw: string, initial: bool, connector: bool}>>
     */
    private static function sequences(array $tokens): array
    {
        $seqs = [];
        $cur = [];
        $pending = [];
        $close = function () use (&$seqs, &$cur, &$pending) {
            if ($cur !== []) {
                $seqs[] = $cur;
            }
            $cur = [];
            $pending = [];
        };
        foreach ($tokens as $t) {
            $isConnector = in_array(mb_strtolower($t['raw']), Lexicon::NAME_CONNECTORS, true);
            if ($cur !== [] && $isConnector && ! $t['initial']) {
                $pending[] = ['raw' => $t['raw'], 'initial' => false, 'connector' => true];
                if ($t['break']) {
                    $close();
                }
                continue;
            }
            if (self::isCapitalized($t['raw']) && ! ($cur !== [] && $t['initial'])) {
                $cur = array_merge($cur, $pending, [['raw' => $t['raw'], 'initial' => $t['initial'], 'connector' => false]]);
                $pending = [];
                if ($t['break']) {
                    $close();
                }
                continue;
            }
            $close();
            if (self::isCapitalized($t['raw'])) {
                $cur = [['raw' => $t['raw'], 'initial' => $t['initial'], 'connector' => false]];
                if ($t['break']) {
                    $close();
                }
            }
        }
        $close();

        return $seqs;
    }

    /** Maiúscula inicial, pelo menos uma minúscula e sem algarismos ("Lisboa", "Mota-Engil"; não "PSD", "Q36"). */
    private static function isCapitalized(string $word): bool
    {
        return (bool) preg_match('/^\p{Lu}/u', $word) && (bool) preg_match('/\p{Ll}/u', $word) && ! preg_match('/\p{N}/u', $word);
    }

    /** Alguma palavra (ou expressão) da sequência é um lugar, uma marca, um clube ou uma instituição. */
    private static function isNotPerson(array $words): bool
    {
        return TermMatcher::any(TermMatcher::haystack([implode(' ', $words)]), Lexicon::NOT_PERSON);
    }
}
