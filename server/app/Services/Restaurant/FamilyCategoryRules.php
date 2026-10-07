<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

/**
 * XPLENDOR — F1-3: categorias de marketing das famílias do PingWin e as REGRAS que as
 * sugerem a partir do caminho da família ("Família \ Comidas \ Francesinhas"). As regras
 * só sugerem: a categoria só conta depois de a equipa a confirmar. O que as regras não
 * reconhecerem fica para a IA (FamilyCategoryAiSuggester).
 */
final class FamilyCategoryRules
{
    /** Categorias (chave => rótulo), pela ordem do desenho. */
    public const CATEGORIES = [
        'pratos' => 'Pratos principais',
        'petiscos' => 'Petiscos e entradas',
        'acompanhamentos' => 'Acompanhamentos',
        'sobremesas' => 'Sobremesas',
        'cafetaria' => 'Cafetaria',
        'cerveja' => 'Cerveja',
        'vinho' => 'Vinho',
        'cocktails' => 'Cocktails e espirituosas',
        'sem_alcool' => 'Sem álcool',
        'infantil' => 'Menu infantil',
        'entrega' => 'Entrega',
        'outros' => 'Outros',
        'excluir' => 'Excluir',
    ];

    /**
     * Palavras por categoria, por ordem de prioridade (a entrega primeiro: "Uber Eats \
     * Comida" é Entrega, decisão 3). Uma palavra com 4 ou mais letras apanha as palavras
     * que começam por ela ("cerv" → "cerveja"); as mais curtas têm de ser exatas.
     */
    private const KEYWORDS = [
        'entrega' => ['uber', 'glovo', 'bolt', 'takeaway', 'entrega', 'delivery'],
        'excluir' => ['staff', 'producao', 'fornecimentos', 'interno', 'taxa', 'sacos'],
        'infantil' => ['crianca', 'criancas', 'infantil', 'kids'],
        'sobremesas' => ['sobremesa', 'doces', 'gelado'],
        'cafetaria' => ['cafetaria', 'cafe', 'cafes', 'cha', 'chas'],
        'cerveja' => ['cerveja', 'cerv', 'beer', 'imperial'],
        'vinho' => ['vinho', 'porto', 'portos', 'espumante', 'champanhe'],
        'cocktails' => ['espirituosa', 'cocktail', 'gin', 'licor', 'whisky', 'shots'],
        'sem_alcool' => ['sumo', 'sumos', 'agua', 'aguas', 'refrigerante', 'limonada'],
        'acompanhamentos' => ['acompanhamento', 'guarnicao', 'guarnicoes'],
        'petiscos' => ['petisco', 'tapa', 'tapas', 'entrada', 'aperitivo', 'couvert'],
        'pratos' => ['prato', 'francesinha', 'cozinha', 'carne', 'carnes', 'peixe', 'peixes', 'hamburguer', 'pizza', 'massa', 'massas'],
    ];

    public static function label(?string $key): ?string
    {
        return $key === null ? null : (self::CATEGORIES[$key] ?? null);
    }

    public static function isValid(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::CATEGORIES);
    }

    /**
     * Categoria sugerida pelas regras, ou null. Procura primeiro na família folha e depois
     * nos níveis acima (sem a raiz "Família"); a entrega vale em qualquer nível.
     */
    public static function suggest(?string $familyPath): ?string
    {
        $segments = self::segments($familyPath);
        if ($segments === []) {
            return null;
        }
        foreach ($segments as $segment) {
            if (self::matches($segment, self::KEYWORDS['entrega'])) {
                return 'entrega';
            }
        }
        foreach (array_reverse($segments) as $segment) {
            foreach (self::KEYWORDS as $category => $words) {
                if ($category !== 'entrega' && self::matches($segment, $words)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /** Folha do caminho ("Francesinhas"), para mostrar. */
    public static function leaf(?string $familyPath): string
    {
        $segments = self::rawSegments($familyPath);

        return $segments === [] ? '' : end($segments);
    }

    /** @return array<int, string> segmentos normalizados, sem a raiz "Família" */
    private static function segments(?string $path): array
    {
        return array_map(fn ($s) => self::normalize($s), self::rawSegments($path));
    }

    /** @return array<int, string> */
    private static function rawSegments(?string $path): array
    {
        $parts = array_values(array_filter(array_map('trim', explode('\\', (string) $path)), fn ($s) => $s !== ''));
        if ($parts !== [] && self::normalize($parts[0]) === 'familia') {
            array_shift($parts);
        }

        return $parts;
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    private static function matches(string $segment, array $words): bool
    {
        $tokens = explode(' ', $segment);
        foreach ($tokens as $token) {
            foreach ($words as $word) {
                if ($token === $word || (strlen($word) >= 4 && str_starts_with($token, $word))) {
                    return true;
                }
            }
        }

        return false;
    }
}
