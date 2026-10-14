<?php

declare(strict_types=1);

namespace App\Services\Radar\Filter;

use App\Services\Radar\RadarText;

/**
 * Radar: o filtro de segurança (DESENHO.md, secção 5). Corre quando o item entra, antes de
 * qualquer IA, só com regras, e não se desliga.
 *  · Bloqueia: tragédias, mortes, acidentes, crimes, doenças, política, religião e conflitos
 *    (no título, nos títulos das notícias relacionadas e nas categorias do feed; as categorias
 *    "Política", "Justiça" e "Religião" contam como o motivo respetivo).
 *  · Nome de pessoa: marca nome_pessoa; passa só com uma palavra de evento público e nenhuma de
 *    vida pessoal; sem nenhuma das duas, bloqueia (vida_pessoal).
 *  · Marca protegida e desporto: passam, com a marca (o cartão avisa; o desporto só chega a
 *    quem o pedir, no R2).
 * Os temas a evitar do perfil da marca são por empresa e ficam para o encaixe (R2).
 */
final class RadarSafetyFilter
{
    /**
     * @param  string[]  $relatedTitles  as notícias relacionadas (Google Trends)
     * @param  string[]  $categories  as categorias do item no feed
     * @return array{verdict: 'passed'|'blocked', reasons: string[], flags: string[]}
     */
    public function check(string $title, array $relatedTitles = [], array $categories = []): array
    {
        $title = RadarText::cleanTitle($title);
        $relatedTitles = array_values(array_filter(array_map(fn ($t) => RadarText::cleanTitle((string) $t), $relatedTitles)));
        $categories = array_values(array_filter(array_map(fn ($c) => RadarText::cleanTitle((string) $c), $categories)));
        $titles = array_merge([$title], $relatedTitles);
        $everything = TermMatcher::haystack(array_merge($titles, $categories));
        $reasons = [];
        $flags = [];

        foreach (Lexicon::BLOCKING as $reason => $terms) {
            if (TermMatcher::any($everything, $terms)) {
                $reasons[] = $reason;
            }
        }
        foreach (Lexicon::BLOCKING_CASE_SENSITIVE as $reason => $terms) {
            if (TermMatcher::anyCaseSensitive(array_merge($titles, $categories), $terms)) {
                $reasons[] = $reason;
            }
        }
        foreach ($categories as $category) {
            $key = RadarText::normalize($category);
            foreach (Lexicon::CATEGORY_REASONS as $name => $reason) {
                if ($key === RadarText::normalize($name)) {
                    $reasons[] = $reason;
                }
            }
            foreach (Lexicon::CATEGORY_FLAGS as $name => $flag) {
                if ($key === RadarText::normalize($name)) {
                    $flags[] = $flag;
                }
            }
        }

        if (TermMatcher::any($everything, Lexicon::PROTECTED_BRANDS) || TermMatcher::anyCaseSensitive($titles, Lexicon::PROTECTED_BRANDS_CASE_SENSITIVE)) {
            $flags[] = 'marca_protegida';
        }

        $match = self::looksLikeMatch($title);
        if ($match || TermMatcher::any($everything, Lexicon::SPORTS)) {
            $flags[] = 'desporto';
        }

        if (PersonNameDetector::inTitle($title) || ($relatedTitles !== [] && PersonNameDetector::trendQueryIsName($title, $relatedTitles))) {
            $flags[] = 'nome_pessoa';
            $words = TermMatcher::haystack($titles);
            $personal = TermMatcher::any($words, Lexicon::PERSONAL_LIFE);
            $event = $match || TermMatcher::any($words, Lexicon::PUBLIC_EVENT);
            if ($personal || ! $event) {
                $reasons[] = 'vida_pessoal';
            }
        }

        $reasons = array_values(array_intersect(Lexicon::REASONS, array_unique($reasons)));
        $flags = array_values(array_intersect(Lexicon::FLAGS, array_unique($flags)));

        return ['verdict' => $reasons === [] ? 'passed' : 'blocked', 'reasons' => $reasons, 'flags' => $flags];
    }

    /**
     * Uma consulta do Trends com a forma de um jogo ("benfica - porto", "portugal x hungria",
     * "nadal vs alcaraz"): é desporto e é um evento público. Só nas consultas em minúsculas e
     * curtas, para não apanhar títulos de jornais com um travessão.
     */
    public static function looksLikeMatch(string $title): bool
    {
        if (preg_match('/\p{Lu}/u', $title) || count(preg_split('/\s+/u', trim($title)) ?: []) > 6) {
            return false;
        }

        return (bool) preg_match('/^\s*[\p{L}\p{N}.\' ]+?\s+(?:-|–|x|vs\.?|v)\s+[\p{L}\p{N}.\' ]+\s*$/u', $title);
    }
}
