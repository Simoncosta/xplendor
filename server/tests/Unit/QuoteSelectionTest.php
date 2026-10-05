<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Quotes\QuoteCalculator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Aceitação parcial: o cálculo do servidor a partir da versão congelada. */
class QuoteSelectionTest extends TestCase
{
    private function snapshot(array $global, array $lines): array
    {
        return ['lines' => $lines, 'global_discount' => $global + ['label' => 'Desconto de pacote']];
    }

    private function line(int $key, string $name, string $billing, float $price, bool $optional = false, bool $package = false): array
    {
        return ['key' => $key, 'name' => $name, 'unit' => $billing === 'monthly' ? 'month' : 'project', 'billing_type' => $billing,
            'quantity' => 1, 'unit_price' => $price, 'is_optional' => $optional, 'in_package' => $package];
    }

    public function test_amount_discount_leaves_when_its_total_has_no_lines_left(): void
    {
        $s = $this->snapshot(['type' => 'amount', 'value' => 50, 'target' => 'one_off'], [
            $this->line(0, 'Social Media', 'monthly', 200),
            $this->line(1, 'Website', 'one_off', 500, true),
        ]);

        $r = QuoteCalculator::computeSelection($s, []);
        $this->assertFalse($r['discount']['applies']);
        $this->assertSame('Desconto de pacote: aplica-se aos serviços de valor único, que não foram incluídos.', $r['discount']['reason']);
        $this->assertSame([200.0, 0.0], [$r['buckets']['monthly']['total'], $r['buckets']['one_off']['total']]);

        $full = QuoteCalculator::computeSelection($s, [1]);
        $this->assertTrue($full['discount']['applies']);
        $this->assertSame(450.0, $full['buckets']['one_off']['total']);
    }

    public function test_discount_without_package_lines_applies_to_what_remains(): void
    {
        $s = $this->snapshot(['type' => 'percent', 'value' => 10], [
            $this->line(0, 'Social Media', 'monthly', 200),
            $this->line(1, 'Tráfego Pago', 'monthly', 200, true),
        ]);

        $r = QuoteCalculator::computeSelection($s, []);
        $this->assertTrue($r['discount']['applies']);
        $this->assertSame(180.0, $r['buckets']['monthly']['total']);
    }

    public function test_several_package_lines_out_are_named_in_the_reason(): void
    {
        $s = $this->snapshot(['type' => 'percent', 'value' => 10], [
            $this->line(0, 'Social Media', 'monthly', 200, false, true),
            $this->line(1, 'Tráfego Pago', 'monthly', 200, true, true),
            $this->line(2, 'Email', 'monthly', 50, true, true),
        ]);

        $r = QuoteCalculator::computeSelection($s, []);
        $this->assertSame('Desconto de pacote: deixa de se aplicar porque os serviços Tráfego Pago e Email não foram incluídos.', $r['discount']['reason']);
    }

    public function test_old_versions_without_keys_accept_only_everything(): void
    {
        $s = ['lines' => [
            ['name' => 'Social Media', 'unit' => 'month', 'billing_type' => 'monthly', 'quantity' => 1, 'unit_price' => 200],
            ['name' => 'Website', 'unit' => 'project', 'billing_type' => 'one_off', 'quantity' => 1, 'unit_price' => 500],
        ], 'global_discount' => ['type' => null, 'value' => null]];

        $r = QuoteCalculator::computeSelection($s, []);
        $this->assertSame([0, 1], $r['accepted_keys']);
        $this->expectException(ValidationException::class);
        QuoteCalculator::computeSelection($s, [1]);
    }
}
