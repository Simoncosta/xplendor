<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Company;
use App\Support\CompanyName;
use Tests\TestCase;

/** O nome da empresa nos textos: com o artigo dela, as contrações; sem artigo, nem artigo nem contração. */
class CompanyNameTest extends TestCase
{
    public static function contractions(): array
    {
        return [
            ['em', 'o', 'no Pinheiro'], ['em', 'a', 'na Pinheiro'], ['em', 'os', 'nos Pinheiro'], ['em', 'as', 'nas Pinheiro'],
            ['de', 'o', 'do Pinheiro'], ['de', 'a', 'da Pinheiro'], ['de', 'os', 'dos Pinheiro'], ['de', 'as', 'das Pinheiro'],
            ['a', 'o', 'ao Pinheiro'], ['a', 'a', 'à Pinheiro'], ['a', 'os', 'aos Pinheiro'], ['a', 'as', 'às Pinheiro'],
            ['em', null, 'em Pinheiro'], ['de', null, 'de Pinheiro'], ['a', null, 'a Pinheiro'], ['em', 'xx', 'em Pinheiro'],
        ];
    }

    /** @dataProvider contractions */
    public function test_contracts_the_preposition_with_the_article(string $preposition, ?string $article, string $expected): void
    {
        $this->assertSame($expected, CompanyName::contract($preposition, 'Pinheiro', $article));
    }

    public function test_reads_the_name_and_the_article_of_the_company(): void
    {
        $company = new Company(['fiscal_name' => 'Pinheiros, Lda.', 'trade_name' => 'Pinheiros', 'name_article' => 'os']);
        $this->assertSame('nos Pinheiros', CompanyName::with('em', $company));
        $this->assertSame('os Pinheiros', CompanyName::withArticle($company));

        $plain = new Company(['fiscal_name' => 'Adega Central, Lda.']);
        $this->assertSame('em Adega Central, Lda.', CompanyName::with('em', $plain));
        $this->assertSame('Adega Central, Lda.', CompanyName::withArticle($plain));
    }
}
