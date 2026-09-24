<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PingwinService;
use Tests\TestCase;

/**
 * XPLENDOR — Conversão de preço na FRONTEIRA (cêntimos ↔ decimal string do PingWin).
 * Cobre os casos-armadilha: 2 casas sempre, zero à esquerda, SEM separador de
 * milhares (partiria o JSON), e o inverso com ROUND (não truncagem).
 */
class PingwinPriceTest extends TestCase
{
    public function test_cents_to_decimal_string_covers_traps(): void
    {
        $this->assertSame('10.25', PingwinService::centsToDecimalString(1025));   // caso base
        $this->assertSame('10.00', PingwinService::centsToDecimalString(1000));   // 2 casas (nunca "10"/"10.0")
        $this->assertSame('0.05', PingwinService::centsToDecimalString(5));       // zero à esquerda
        $this->assertSame('1000.00', PingwinService::centsToDecimalString(100000)); // SEM milhares ("1,000.00" partiria o JSON)
        $this->assertSame('0.00', PingwinService::centsToDecimalString(0));
    }

    public function test_decimal_to_cents_rounds_not_truncates(): void
    {
        $this->assertSame(1025, PingwinService::decimalToCents('10.25'));
        $this->assertSame(1100, PingwinService::decimalToCents('10.999')); // ROUND → 1100 (não 1099)
        $this->assertSame(5, PingwinService::decimalToCents('0.05'));
        $this->assertSame(100000, PingwinService::decimalToCents('1000.00'));
        $this->assertSame(1025, PingwinService::decimalToCents(10.25)); // aceita float
    }
}
