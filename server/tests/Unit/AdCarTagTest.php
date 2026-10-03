<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MetaAd;
use App\Support\AdCarTag;
use PHPUnit\Framework\TestCase;

/**
 * Tag [id:N] no nome do anúncio — parsing e resolução (funções puras).
 */
class AdCarTagTest extends TestCase
{
    /** @dataProvider variants */
    public function test_parse_variants(?string $name, bool $hasTag, array $ids, array $invalid): void
    {
        $p = AdCarTag::parse($name);

        $this->assertSame($hasTag, $p['has_tag']);
        $this->assertSame($ids, $p['ids']);
        $this->assertSame($invalid, $p['invalid_tokens']);
    }

    public static function variants(): array
    {
        return [
            'simples'                => ['Golf GTI [id:89]', true, [89], []],
            'espaço depois de :'     => ['Golf GTI [id: 89]', true, [89], []],
            'espaços por todo o lado' => ['Golf [ id : 89 ]', true, [89], []],
            'maiúsculas'             => ['Golf [ID:89]', true, [89], []],
            'misto'                  => ['Golf [Id:89]', true, [89], []],
            'dois IDs'               => ['Dupla [id:89,113]', true, [89, 113], []],
            'dois IDs com espaços'   => ['Dupla [id: 89 , 113]', true, [89, 113], []],
            'repetido'               => ['[id:89,89]', true, [89], []],
            'duas tags'              => ['[id:89] e [id:113]', true, [89, 113], []],
            'zeros à esquerda'       => ['[id:089]', true, [89], []],
            'sem tag'                => ['Campanha geral de stock', false, [], []],
            'nome vazio'             => ['', false, [], []],
            'nome null'              => [null, false, [], []],
            'parecido mas não é'     => ['Golf id:89 (sem parênteses)', false, [], []],
            'outra etiqueta'         => ['Golf [ref:89]', false, [], []],
            'tag vazia'              => ['Golf [id:]', true, [], []],
            'não numérico'           => ['Golf [id:abc]', true, [], ['abc']],
            'zero'                   => ['Golf [id:0]', true, [], ['0']],
            'negativo'               => ['Golf [id:-5]', true, [], ['-5']],
            'válido e lixo'          => ['Golf [id:89,x1]', true, [89], ['x1']],
            'número gigante'         => ['[id:1234567890123456789012]', true, [], ['1234567890123456789012']],
        ];
    }

    public function test_resolve_untagged_goes_to_general_stock_without_warning(): void
    {
        $r = AdCarTag::resolve(AdCarTag::parse('Stock geral'), [89]);

        $this->assertSame(MetaAd::TAG_UNTAGGED, $r['status']);
        $this->assertSame([], $r['car_ids']);
        $this->assertSame([], $r['invalid_ids']);
    }

    public function test_resolve_matched_split_and_invalid(): void
    {
        $this->assertSame(MetaAd::TAG_MATCHED, AdCarTag::resolve(AdCarTag::parse('[id:89]'), [89, 113])['status']);

        $split = AdCarTag::resolve(AdCarTag::parse('[id:89,113]'), [89, 113]);
        $this->assertSame(MetaAd::TAG_SPLIT, $split['status']);
        $this->assertSame([89, 113], $split['car_ids']);

        // ID inexistente / de outra empresa → inválido, nunca atribuído.
        $invalid = AdCarTag::resolve(AdCarTag::parse('[id:999]'), [89]);
        $this->assertSame(MetaAd::TAG_INVALID, $invalid['status']);
        $this->assertSame([], $invalid['car_ids']);
        $this->assertSame(['999'], $invalid['invalid_ids']);

        // Tag vazia ou só lixo → inválida (não cai em silêncio no stock geral).
        $this->assertSame(MetaAd::TAG_INVALID, AdCarTag::resolve(AdCarTag::parse('[id:]'), [89])['status']);
        $this->assertSame(['abc'], AdCarTag::resolve(AdCarTag::parse('[id:abc]'), [89])['invalid_ids']);
    }

    public function test_resolve_mixed_valid_and_invalid_splits_only_valid_and_warns(): void
    {
        $r = AdCarTag::resolve(AdCarTag::parse('[id:89,999,113]'), [89, 113]);

        $this->assertSame(MetaAd::TAG_SPLIT, $r['status']);
        $this->assertSame([89, 113], $r['car_ids']);
        $this->assertSame(['999'], $r['invalid_ids']);

        $one = AdCarTag::resolve(AdCarTag::parse('[id:89,999]'), [89]);
        $this->assertSame(MetaAd::TAG_MATCHED, $one['status']);
        $this->assertSame([89], $one['car_ids']);
        $this->assertSame(['999'], $one['invalid_ids']);
    }

    public function test_resolve_removed_car_keeps_receiving_spend(): void
    {
        $r = AdCarTag::resolve(AdCarTag::parse('[id:89]'), [], [89]);

        $this->assertSame(MetaAd::TAG_MATCHED, $r['status']);
        $this->assertSame([89], $r['car_ids']);
        $this->assertSame([89], $r['removed_ids']);
        $this->assertSame([], $r['invalid_ids']);
    }

    public function test_split_evenly_sums_exactly(): void
    {
        $this->assertSame([5.0, 5.0], AdCarTag::splitEvenly(10.0, 2));
        $parts = AdCarTag::splitEvenly(10.0, 3);
        $this->assertSame([3.3333, 3.3333, 3.3334], $parts);
        $this->assertEqualsWithDelta(10.0, array_sum($parts), 1e-9);
        $this->assertEqualsWithDelta(10.01, array_sum(AdCarTag::splitEvenly(10.01, 2)), 1e-9);
        $this->assertSame([], AdCarTag::splitEvenly(10.0, 0));
    }
}
