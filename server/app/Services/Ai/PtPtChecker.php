<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Verificador do português de Portugal nas respostas da IA: procura marcas do português do
 * Brasil (você, usuário, celular, equipe, contato, registro, tela, arquivo, ônibus, "a gente",
 * o gerúndio como "estou fazendo") e travessões. Devolve as marcas encontradas (sem repetir),
 * para pedir de novo uma vez e, se persistirem, mostrar o aviso "Rever o português".
 */
final class PtPtChecker
{
    /** Marca => expressão (palavra inteira, sem distinguir maiúsculas). */
    private const MARKS = [
        'você' => '/\bvocês?\b/iu',
        'usuário' => '/\busu[áa]ri[oa]s?\b/iu',
        'celular' => '/\bcelular(es)?\b/iu',
        'equipe' => '/\bequipes?\b/iu',
        'contato' => '/\bcontatos?\b/iu',
        'registro' => '/\bregistros?\b/iu',
        'tela' => '/\btelas?\b/iu',
        'arquivo' => '/\barquivos?\b/iu',
        'ônibus' => '/\b[ôo]nibus\b/iu',
        'a gente' => '/\ba gente\b/iu',
        'gerúndio ("estou fazendo")' => '/\b(estou|est[áa]s?|estamos|est[ãa]o|estava|estavam|estive|esteve|estiveram)\s+[a-zà-ú]+(ando|endo|indo)\b/iu',
        'travessão' => '/[\x{2014}\x{2013}]/u',
    ];

    /** @return string[] as marcas encontradas */
    public static function issues(string $text): array
    {
        $found = [];
        foreach (self::MARKS as $label => $regex) {
            if (preg_match($regex, $text)) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /** Todas as cadeias de uma resposta (texto ou JSON), juntas para verificar. */
    public static function issuesIn(mixed $value): array
    {
        return self::issues(implode("\n", self::strings($value)));
    }

    /** @return string[] */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (is_array($value)) {
            return array_merge([], ...array_map(fn ($v) => self::strings($v), array_values($value)));
        }

        return [];
    }

    /** O pedido de correção que segue na segunda tentativa. */
    public static function retryInstruction(array $issues): string
    {
        return 'A resposta anterior tinha marcas que não são de português de Portugal ou travessões ('
            . implode(', ', $issues) . '). Reescreva toda a resposta em português de Portugal formal, a tratar o leitor por "a sua marca" '
            . 'e nunca por "você", sem travessões (use vírgula, dois pontos ou ponto final), e mantenha exatamente o mesmo formato.';
    }
}
