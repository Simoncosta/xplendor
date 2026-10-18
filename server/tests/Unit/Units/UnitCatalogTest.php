<?php

declare(strict_types=1);

namespace Tests\Unit\Units;

use App\Models\PingwinUnit;
use App\Services\Restaurant\Units\UnitCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Auditoria v2.1 · regra 1: o catálogo das unidades globais. Reutiliza a unidade com a mesma base e a
 * mesma quantidade (e um nome que não diga outra); só cria se não existir, com o nome
 * "<Embalagem> <N> <base>"; nunca um nome repetido.
 */
class UnitCatalogTest extends TestCase
{
    private function catalog(array $rows): UnitCatalog
    {
        $units = [];
        foreach ($rows as [$id, $d, $parent, $v, $active, $product]) {
            $u = new PingwinUnit();
            $u->forceFill(['pingwin_id' => $id, 'description' => $d, 'parent_pingwin_id' => $parent, 'unit_value' => $v, 'is_active' => $active, 'product_pingwin_id' => $product]);
            $units[$id] = $u;
        }

        return new UnitCatalog($units);
    }

    private function base(): array
    {
        return [
            ['11001', 'Unidade', null, 1, true, ''], ['11002', 'Quilograma', null, 1, true, ''], ['11003', 'Litro', null, 1, true, ''],
            ['CX6', 'Caixa 6 Unidades', '11001', 1, true, ''],      // incoerente: o nome diz 6, conta 1
            ['GRD24', 'Grade 24', '11001', 24, true, ''],
            ['SACO5', 'Saco 5kg', '11002', 1, true, ''],            // incoerente
            ['OLD6', 'Caixa seis', '11001', 6, false, ''],          // anulada: não se reutiliza
        ];
    }

    public function test_names(): void
    {
        $this->assertSame('Caixa 6 un', UnitCatalog::name('caixa', 6, 'un'));
        $this->assertSame('Grade 24 un', UnitCatalog::name('grade', 24, 'un'));
        $this->assertSame('Caixa 180 un', UnitCatalog::name(null, 180, 'un'));
        $this->assertSame('Saco 5 kg', UnitCatalog::name('saco', 5, 'kg'));
        $this->assertSame('Bidon 30 L', UnitCatalog::name('bidon', 30, 'L'));
        $this->assertSame('Caixa 28 un', UnitCatalog::name('lata', 28, 'un'));
        $this->assertSame('Saco 2,5 kg', UnitCatalog::name('saco', 2.5, 'kg'));
    }

    public function test_reuses_the_global_unit_with_the_same_base_and_quantity(): void
    {
        $r = $this->catalog($this->base())->resolve('11001', 24, 'un', 'caixa');
        $this->assertSame('GRD24', $r['reuse']->pingwin_id);
        $this->assertArrayNotHasKey('create', $r);
    }

    public function test_never_reuses_an_incoherent_or_voided_unit_and_creates_with_the_rule_name(): void
    {
        $c = $this->catalog($this->base());
        // "Caixa 6 Unidades" conta 1 (não 6) e "Caixa seis" está anulada: cria-se "Caixa 6 un".
        $this->assertSame(['create' => ['description' => 'Caixa 6 un', 'parent_qnt' => 6.0], 'name' => 'Caixa 6 un'], $c->resolve('11001', 6, 'un', 'caixa'));
        // Uma unidade cujo nome diz outra quantidade nunca se reutiliza, mesmo com a quantidade certa.
        $c2 = $this->catalog(array_merge($this->base(), [['X1', 'Caixa 12 Unidades', '11001', 6, true, '']]));
        $this->assertArrayHasKey('create', $c2->resolve('11001', 6, 'un', 'caixa'));
        // Noutra base não serve: 5 kg no Quilograma (o "Saco 5kg" conta 1).
        $this->assertSame('Saco 5 kg', $c->resolve('11002', 5, 'kg', 'saco')['create']['description']);
    }

    public function test_never_a_repeated_name(): void
    {
        $c = $this->catalog(array_merge($this->base(), [['T', 'Caixa 6 un', '11001', 1, true, '']]));
        $this->assertTrue($c->nameTaken('caixa  6 UN'));
        $this->assertSame('Caixa de 6 un', $c->resolve('11001', 6, 'un', 'caixa')['create']['description']);
    }

    public function test_quantity_from_the_name(): void
    {
        $this->assertSame(24.0, UnitCatalog::nameQuantity('Caixa 24 Unidades', 'un'));
        $this->assertSame(5.0, UnitCatalog::nameQuantity('Saco 5kg', 'kg'));
        $this->assertSame(0.25, UnitCatalog::nameQuantity('Saco 250gr', 'kg'));
        $this->assertSame(24.0, UnitCatalog::nameQuantity('SB 0.33X24 GR', 'un'));
        $this->assertNull(UnitCatalog::nameQuantity('Unidade', 'un'));
    }
}
