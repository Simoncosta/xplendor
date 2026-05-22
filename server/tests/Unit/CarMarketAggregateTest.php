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
}
