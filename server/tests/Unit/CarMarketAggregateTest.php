<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CarMarketAggregate;
use Tests\TestCase;

class CarMarketAggregateTest extends TestCase
{
    private function makeAggregate(array $attrs = []): CarMarketAggregate
    {
        $agg = new CarMarketAggregate();

        foreach ($attrs as $key => $value) {
            $agg->setAttribute($key, $value);
        }

        return $agg;
    }

    // -------------------------------------------------------------------------
    // priceSignal thresholds
    // -------------------------------------------------------------------------

    public function test_priceSignal_overpriced_at_ten_percent_above(): void
    {
        $agg = $this->makeAggregate(['median_price' => 20000, 'car_price_gross' => 22001]);
        $this->assertSame('overpriced', $agg->priceSignal()); // +10.005%
    }

    public function test_priceSignal_slightly_high_between_three_and_ten_percent(): void
    {
        $agg = $this->makeAggregate(['median_price' => 20000, 'car_price_gross' => 21000]);
        $this->assertSame('slightly_high', $agg->priceSignal()); // +5%
    }

    public function test_priceSignal_fair_at_three_percent_exactly(): void
    {
        $agg = $this->makeAggregate(['median_price' => 20000, 'car_price_gross' => 20600]);
        $this->assertSame('slightly_high', $agg->priceSignal()); // +3%
    }

    public function test_priceSignal_fair_within_zero_to_three_percent(): void
    {
        $agg = $this->makeAggregate(['median_price' => 20000, 'car_price_gross' => 20400]);
        $this->assertSame('fair', $agg->priceSignal()); // +2%
    }

    public function test_priceSignal_fair_at_zero_percent(): void
    {
        $agg = $this->makeAggregate(['median_price' => 20000, 'car_price_gross' => 20000]);
        $this->assertSame('fair', $agg->priceSignal()); // 0%
    }

    public function test_priceSignal_fair_at_negative_five_percent(): void
    {
        $agg = $this->makeAggregate(['median_price' => 20000, 'car_price_gross' => 19000]);
        $this->assertSame('fair', $agg->priceSignal()); // -5%
    }

    public function test_priceSignal_competitive_below_negative_five_percent(): void
    {
        $agg = $this->makeAggregate(['median_price' => 20000, 'car_price_gross' => 18000]);
        $this->assertSame('competitive', $agg->priceSignal()); // -10%
    }

    public function test_priceSignal_null_when_no_median(): void
    {
        $agg = $this->makeAggregate(['median_price' => null, 'car_price_gross' => 20000]);
        $this->assertNull($agg->priceSignal());
    }

    public function test_priceSignal_null_when_no_car_price(): void
    {
        $agg = $this->makeAggregate(['median_price' => 20000, 'car_price_gross' => null]);
        $this->assertNull($agg->priceSignal());
    }

    public function test_priceDifference_correct_calculation(): void
    {
        $agg = $this->makeAggregate(['median_price' => 20000, 'car_price_gross' => 21000]);
        $this->assertSame(5.0, $agg->priceDifference());
    }

    public function test_priceDifference_negative_when_below_market(): void
    {
        $agg = $this->makeAggregate(['median_price' => 20000, 'car_price_gross' => 18000]);
        $this->assertSame(-10.0, $agg->priceDifference());
    }

    // -------------------------------------------------------------------------
    // promo price — effectivePrice / priceDifference / priceSignal
    // -------------------------------------------------------------------------

    public function test_effectivePrice_returns_promo_when_set(): void
    {
        $agg = $this->makeAggregate([
            'car_price_gross'   => 19950,
            'promo_price_gross' => 17500,
        ]);
        $this->assertSame(17500.0, $agg->effectivePrice());
    }

    public function test_effectivePrice_returns_gross_when_no_promo(): void
    {
        $agg = $this->makeAggregate([
            'car_price_gross'   => 19950,
            'promo_price_gross' => null,
        ]);
        $this->assertSame(19950.0, $agg->effectivePrice());
    }

    public function test_priceDifference_uses_promo_price_when_set(): void
    {
        // median 18000; promo 17500 → (17500-18000)/18000 = -2.78%
        $agg = $this->makeAggregate([
            'median_price'      => 18000,
            'car_price_gross'   => 19950,
            'promo_price_gross' => 17500,
        ]);
        $this->assertSame(-2.78, $agg->priceDifference());
    }

    public function test_priceSignal_competitive_when_promo_below_market(): void
    {
        // gross would be slightly_high (+5%), promo is competitive (-8.3%)
        $agg = $this->makeAggregate([
            'median_price'      => 18000,
            'car_price_gross'   => 18900,  // +5% → slightly_high without promo
            'promo_price_gross' => 16500,  // -8.33% → competitive with promo
        ]);
        $this->assertSame('competitive', $agg->priceSignal());
    }

    public function test_priceSignal_unchanged_without_promo(): void
    {
        // same as test_priceSignal_slightly_high_between_three_and_ten_percent but explicit null promo
        $agg = $this->makeAggregate([
            'median_price'      => 20000,
            'car_price_gross'   => 21000,
            'promo_price_gross' => null,
        ]);
        $this->assertSame('slightly_high', $agg->priceSignal());
    }
}
