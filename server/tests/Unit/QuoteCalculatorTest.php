<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Quotes\QuoteCalculator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Totais do orçamento: MENSAL e VALOR ÚNICO separados (nunca somados), sem IVA;
 * descontos por linha (% e €) e desconto global (% nos dois; € com a regra do total).
 */
class QuoteCalculatorTest extends TestCase
{
    private function line(string $billing, float $qty, float $price, ?string $dType = null, ?float $dValue = null): array
    {
        return ['quantity' => $qty, 'unit_price' => $price, 'billing_type' => $billing, 'discount_type' => $dType, 'discount_value' => $dValue];
    }

    public function test_monthly_and_one_off_totals_are_separate_and_never_summed(): void
    {
        $r = QuoteCalculator::compute([
            $this->line('monthly', 1, 200), $this->line('monthly', 1, 200), $this->line('one_off', 12, 25),
        ]);

        $this->assertSame(400.0, $r['buckets']['monthly']['total']);
        $this->assertSame(300.0, $r['buckets']['one_off']['total']);
        $this->assertSame(['monthly', 'one_off'], array_keys($r['buckets']));   // não existe um total combinado
    }

    public function test_line_discounts_in_percent_and_euros(): void
    {
        $r = QuoteCalculator::compute([
            $this->line('one_off', 12, 25, 'percent', 10),     // 300 − 10% = 270
            $this->line('monthly', 2, 150, 'amount', 50),     // 300 − 50 = 250
        ]);

        $this->assertSame(270.0, $r['lines'][0]['line_total']);
        $this->assertSame(250.0, $r['lines'][1]['line_total']);
        $this->assertSame(30.0, $r['lines'][0]['line_discount']);
    }

    public function test_global_percent_discount_applies_to_both_totals(): void
    {
        $r = QuoteCalculator::compute([$this->line('monthly', 1, 400), $this->line('one_off', 1, 1000)], ['type' => 'percent', 'value' => 10]);

        $this->assertSame([400.0, 40.0, 360.0], [$r['buckets']['monthly']['subtotal'], $r['buckets']['monthly']['discount'], $r['buckets']['monthly']['total']]);
        $this->assertSame([1000.0, 100.0, 900.0], [$r['buckets']['one_off']['subtotal'], $r['buckets']['one_off']['discount'], $r['buckets']['one_off']['total']]);
    }

    public function test_global_euro_discount_applies_only_to_its_total(): void
    {
        $r = QuoteCalculator::compute([$this->line('monthly', 1, 200), $this->line('monthly', 1, 200), $this->line('one_off', 1, 300)],
            ['type' => 'amount', 'value' => 100, 'target' => 'monthly']);

        $this->assertSame(300.0, $r['buckets']['monthly']['total']);
        $this->assertSame(300.0, $r['buckets']['one_off']['total']);   // intocado
        $this->assertSame(0.0, $r['buckets']['one_off']['discount']);
    }

    public function test_global_euro_discount_rules(): void
    {
        $lines = [$this->line('monthly', 1, 200)];
        foreach ([
            [['type' => 'amount', 'value' => 50], 'global_discount_target'],                          // sem dizer a que total
            [['type' => 'amount', 'value' => 50, 'target' => 'one_off'], 'global_discount_target'],   // total sem linhas
            [['type' => 'amount', 'value' => 250, 'target' => 'monthly'], 'global_discount_value'],   // maior do que o total
            [['type' => 'percent', 'value' => 120], 'global_discount_value'],
        ] as [$global, $field]) {
            try {
                QuoteCalculator::compute($lines, $global);
                $this->fail('Devia recusar: ' . json_encode($global));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    public function test_line_discount_cannot_exceed_the_line(): void
    {
        $this->expectException(ValidationException::class);
        QuoteCalculator::compute([$this->line('one_off', 1, 100, 'amount', 150)]);
    }

    public function test_rounding_to_cents(): void
    {
        $r = QuoteCalculator::compute([$this->line('one_off', 3, 33.335, 'percent', 7.5)]);

        $this->assertSame(100.01, $r['lines'][0]['line_subtotal']);
        $this->assertSame(92.51, $r['lines'][0]['line_total']);
    }
}
