<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;

/**
 * O nome da empresa nos textos, com o artigo dela (companies.name_article: o, a, os, as) e as
 * contrações do português: em → no/na/nos/nas, de → do/da/dos/das, a → ao/à/aos/às. Sem artigo, o
 * texto não usa artigo nem contração ("em Pinheiros", "de Pinheiros").
 */
final class CompanyName
{
    public const ARTICLES = ['o', 'a', 'os', 'as'];

    private const CONTRACTIONS = [
        'em' => ['o' => 'no', 'a' => 'na', 'os' => 'nos', 'as' => 'nas'],
        'de' => ['o' => 'do', 'a' => 'da', 'os' => 'dos', 'as' => 'das'],
        'a' => ['o' => 'ao', 'a' => 'à', 'os' => 'aos', 'as' => 'às'],
    ];

    /** O nome que se mostra: o comercial, senão o fiscal. */
    public static function name(Company $company): string
    {
        return trim((string) ($company->trade_name ?: $company->fiscal_name));
    }

    /** "os Pinheiros"; sem artigo, só o nome. */
    public static function withArticle(Company $company): string
    {
        return self::phrase(self::name($company), $company->name_article);
    }

    /** "nos Pinheiros", "dos Pinheiros", "aos Pinheiros"; sem artigo, "em Pinheiros", "de Pinheiros", "a Pinheiros". */
    public static function with(string $preposition, Company $company): string
    {
        return self::contract($preposition, self::name($company), $company->name_article);
    }

    /** As mesmas regras, para um nome e um artigo quaisquer. */
    public static function contract(string $preposition, string $name, ?string $article): string
    {
        $article = in_array($article, self::ARTICLES, true) ? $article : null;
        if ($article === null) {
            return trim("{$preposition} {$name}");
        }
        $joined = self::CONTRACTIONS[$preposition][$article] ?? "{$preposition} {$article}";

        return "{$joined} {$name}";
    }

    public static function phrase(string $name, ?string $article): string
    {
        return in_array($article, self::ARTICLES, true) ? "{$article} {$name}" : $name;
    }
}
