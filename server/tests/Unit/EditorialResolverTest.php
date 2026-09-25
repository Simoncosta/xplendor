<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ContentAnchor;
use App\Services\EditorialAnchorResolver;
use Tests\TestCase;

/**
 * XPLENDOR — Linha Editorial: teste do RESOLVEDOR. O Computus é essencial (as móveis
 * dependem dele); testado contra anos conhecidos. Depois, os 4 tipos de regra.
 */
class EditorialResolverTest extends TestCase
{
    private EditorialAnchorResolver $r;

    protected function setUp(): void
    {
        parent::setUp();
        $this->r = new EditorialAnchorResolver();
    }

    public function test_computus_matches_known_years(): void
    {
        $this->assertSame('2024-03-31', $this->r->easter(2024)->toDateString());
        $this->assertSame('2025-04-20', $this->r->easter(2025)->toDateString());
        $this->assertSame('2026-04-05', $this->r->easter(2026)->toDateString()); // confirmado pela pesquisa
    }

    public function test_easter_relative_anchors_2026(): void
    {
        $mk = fn (int $offset) => (new ContentAnchor(['rule_type' => 'relativa_pascoa', 'easter_offset' => $offset]));
        $this->assertSame('2026-02-17', $this->r->resolve($mk(-47), 2026)['date']); // Carnaval
        $this->assertSame('2026-04-03', $this->r->resolve($mk(-2), 2026)['date']);   // Sexta-feira Santa
        $this->assertSame('2026-04-05', $this->r->resolve($mk(0), 2026)['date']);    // Páscoa
        $this->assertSame('2026-06-04', $this->r->resolve($mk(60), 2026)['date']);   // Corpo de Deus
    }

    public function test_fixa(): void
    {
        $natal = new ContentAnchor(['rule_type' => 'fixa', 'month' => 12, 'day' => 25]);
        $this->assertSame('2026-12-25', $this->r->resolve($natal, 2026)['date']);
    }

    public function test_nth_weekday(): void
    {
        // Dia da Mãe = 1.º domingo de maio 2026 = 3 maio.
        $mae = new ContentAnchor(['rule_type' => 'nth_weekday', 'month' => 5, 'ordinal' => 1, 'weekday' => 0]);
        $this->assertSame('2026-05-03', $this->r->resolve($mae, 2026)['date']);

        // Fim do horário de verão = último domingo de outubro 2026 = 25 out.
        $fimVerao = new ContentAnchor(['rule_type' => 'nth_weekday', 'month' => 10, 'ordinal' => -1, 'weekday' => 0]);
        $this->assertSame('2026-10-25', $this->r->resolve($fimVerao, 2026)['date']);

        // Início do horário de verão = último domingo de março 2026 = 29 mar.
        $inicioVerao = new ContentAnchor(['rule_type' => 'nth_weekday', 'month' => 3, 'ordinal' => -1, 'weekday' => 0]);
        $this->assertSame('2026-03-29', $this->r->resolve($inicioVerao, 2026)['date']);
    }

    public function test_periodo(): void
    {
        $santos = new ContentAnchor(['rule_type' => 'periodo', 'start_month' => 6, 'start_day' => 1, 'end_month' => 6, 'end_day' => 30]);
        $out = $this->r->resolve($santos, 2026);
        $this->assertSame('range', $out['type']);
        $this->assertSame('2026-06-01', $out['start']);
        $this->assertSame('2026-06-30', $out['end']);
    }
}
