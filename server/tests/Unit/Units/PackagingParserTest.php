<?php

declare(strict_types=1);

namespace Tests\Unit\Units;

use App\Services\Restaurant\Units\PackagingParser;
use PHPUnit\Framework\TestCase;

/** A embalagem lida da descrição do fornecedor: os casos reais do guião e variações. */
class PackagingParserTest extends TestCase
{
    /** [descrição, família, peças, conteúdo de cada, total, recipiente, dose] */
    public static function cases(): array
    {
        return [
            // Os casos do guião.
            ['CAFÉ GRÃO 1KG', 'kg', 1, 1.0, 1.0, null, null],
            ['ARROZ AGULHA 5 KG', 'kg', 1, 5.0, 5.0, null, null],
            ['QUEIJO RALADO 500G', 'kg', 1, 0.5, 0.5, null, null],
            ['VINHO TINTO 0,75L', 'L', 1, 0.75, 0.75, null, null],
            ['MOLHO FRANCESINHA 30LT', 'L', 1, 30.0, 30.0, null, null],
            ['CERVEJA BARRIL 50L', 'L', 1, 50.0, 50.0, 'barril', null],
            ['SUPER BOCK 24X0,33', 'L', 24, 0.33, 7.92, 'caixa', null],
            ['AGUA 6X1L', 'L', 6, 1.0, 6.0, 'caixa', null],
            ['OVOS C/12', 'un', 12, 1.0, 12.0, 'caixa', null],
            ['SUMOL GR24', 'un', 24, 1.0, 24.0, 'grade', null],
            ['PACK 6 AGUA 1,5L', 'L', 6, 1.5, 9.0, 'pack', null],
            ['PEDRAS TR 0.25X0.24 GR', 'L', 24, 0.25, 6.0, 'grade', null],
            // Variações reais e de doses.
            ['PEDRAS LIMAO TP 0.25X24TB LN', 'L', 24, 0.25, 6.0, 'caixa', null],
            ['COCA COLA REGULAR LATA 28/033CL', 'L', 28, 0.33, 9.24, 'lata', null],
            ['COCA COLA LATA 28X33CL', 'L', 28, 0.33, 9.24, 'lata', null],
            ['MC OVOS M IND 15DUZIAS', 'un', 180, 1.0, 180.0, 'caixa', null],
            ['OVOS CLASSE M DUZIA', 'un', 12, 1.0, 12.0, 'caixa', null],
            ['DESCAFEINADO MOIDO STICK (60)', 'un', 60, 1.0, 60.0, 'caixa', null],
            ['CAFE DELTA Q 100 CAPS', 'un', 100, 1.0, 100.0, 'caixa', 'Cápsula'],
            ['CAFE CAPSULAS CX 50', 'un', 50, 1.0, 50.0, 'caixa', 'Cápsula'],
            ['ACUCAR SAQ. 1000', 'un', 1000, 1.0, 1000.0, 'caixa', 'Saqueta'],
            ['CHA LIPTON 25 SAQUETAS', 'un', 25, 1.0, 25.0, 'caixa', 'Saqueta'],
            ['MANTEIGA DOSE 10G C/100', 'un', 100, 1.0, 100.0, 'caixa', 'Dose'],
            ['KETCHUP MONODOSE 200X10G', 'un', 200, 1.0, 200.0, 'caixa', 'Dose'],
            ['CAFE PASTILHAS 150', 'un', 150, 1.0, 150.0, 'caixa', 'Pastilha'],
            ['QUEIJO FLAMENGO 40 FATIAS', 'un', 40, 1.0, 40.0, 'caixa', 'Fatia'],
            ['SB STOUT TR 0.33X24  GR TR', 'L', 24, 0.33, 7.92, 'grade', null],
            ['SPRITE 33CL', 'L', 1, 0.33, 0.33, null, null],
            ['AGUA 1,5L X 6', 'L', 6, 1.5, 9.0, 'caixa', null],
            ['FARINHA 12X1KG', 'kg', 12, 1.0, 12.0, 'caixa', null],
            ['BOLA RUSTICA 90G 80UN', 'kg', 80, 0.09, 7.2, 'caixa', null],
            ['SACO BATATA 10KG', 'kg', 1, 10.0, 10.0, 'saco', null],
            ['BIDON OLEO 30L', 'L', 1, 30.0, 30.0, 'bidon', null],
            ['GARRAFAO VINHO 5LT', 'L', 1, 5.0, 5.0, 'garrafa', null],
            ['FARINHA MAIZENA 2.5KG', 'kg', 1, 2.5, 2.5, null, null],
            ['FIAMBRE 250 GR', 'kg', 1, 0.25, 0.25, null, null],
            ['Grade 24', 'un', 24, 1.0, 24.0, 'grade', null],
            ['Barril 50lt', 'L', 1, 50.0, 50.0, 'barril', null],
        ];
    }

    /** @dataProvider cases */
    public function test_reads_the_packaging(string $d, string $family, int $count, float $each, float $total, ?string $container, ?string $dose): void
    {
        $p = PackagingParser::parse($d);
        $this->assertNotNull($p, $d);
        $this->assertSame($family, $p['family'], $d);
        $this->assertSame($count, $p['count'], $d);
        $this->assertEqualsWithDelta($each, $p['each'], 1e-6, $d);
        $this->assertEqualsWithDelta($total, $p['quantity'], 1e-6, $d);
        $this->assertSame($container, $p['container'], $d);
        $this->assertSame($dose, $p['dose'], $d);
    }

    public function test_labels_in_plain_portuguese(): void
    {
        $this->assertSame('Embalagem de 1 kg', PackagingParser::parse('CAFÉ GRÃO 1KG')['label']);
        $this->assertSame('Grade de 24 × 0,25 L', PackagingParser::parse('PEDRAS TR 0.25X0.24 GR')['label']);
        $this->assertSame('Caixa de 100 cápsulas', PackagingParser::parse('CAFE DELTA Q 100 CAPS')['label']);
        $this->assertSame('Caixa de 180 unidades', PackagingParser::parse('MC OVOS M IND 15DUZIAS')['label']);
    }

    public function test_containers_and_doses_without_quantity(): void
    {
        foreach (['OLEO BIDON' => 'bidon', 'FARINHA SACO' => 'saco', 'CX GUARDANAPOS' => 'caixa', 'CERVEJA BARRIL' => 'barril'] as $d => $c) {
            $p = PackagingParser::parse($d);
            $this->assertSame($c, $p['container'], $d);
            $this->assertSame(0.0, $p['quantity'], $d);
        }
        $p = PackagingParser::parse('ACUCAR SAQUETAS');
        $this->assertSame('Saqueta', $p['dose']);
        $this->assertSame(0, $p['count']); // sem o N: pergunta-se, nunca se assume
    }

    public function test_nothing_to_read(): void
    {
        $this->assertNull(PackagingParser::parse('FRANGO'));
        $this->assertNull(PackagingParser::parse('LIMAO KG'));
        $this->assertNull(PackagingParser::parse(''));
    }
}
