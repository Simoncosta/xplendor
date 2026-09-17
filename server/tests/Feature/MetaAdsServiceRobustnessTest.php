<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\MetaAdsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * XPLENDOR — Meta Incremento 1: robustez do MetaAdsService (rate limit, 5xx,
 * falha de ligação) — nunca rebenta o pipeline; retry com backoff em transitórios.
 */
class MetaAdsServiceRobustnessTest extends TestCase
{
    private function service(): MetaAdsService
    {
        return app(MetaAdsService::class);
    }

    public function test_retries_on_429_then_succeeds(): void
    {
        // 1ª resposta 429 (rate limit) → retry → 2ª resposta 200 com dados.
        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'rate limit']], 429)
                ->push(['data' => [['spend' => '12.50', 'impressions' => '100']]], 200),
        ]);

        $result = $this->service()->getInsights('tok', '123', 'ad', '2026-01-01', '2026-01-02');

        $this->assertSame('12.50', $result['spend'] ?? null); // recuperou após o 429
        Http::assertSentCount(2);                              // houve retry
    }

    public function test_gives_up_gracefully_on_repeated_5xx(): void
    {
        // 5xx sempre → esgota retries → devolve [] (sem exceção, pipeline vivo).
        Http::fake(['graph.facebook.com/*' => Http::response('server error', 500)]);

        $result = $this->service()->getInsights('tok', '123', 'ad', '2026-01-01', '2026-01-02');

        $this->assertSame([], $result);
        Http::assertSentCount(4); // MAX_ATTEMPTS (1 + 3 retries)
    }

    public function test_connection_failure_does_not_throw(): void
    {
        // Falha de ligação → retries → Response 503 sintética → getInsights [] sem rebentar.
        Http::fake(fn () => throw new ConnectionException('network down'));

        $result = $this->service()->getInsights('tok', '123', 'ad', '2026-01-01', '2026-01-02');

        $this->assertSame([], $result); // graciosamente vazio, sem exceção
    }

    public function test_success_first_try_no_retry(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [['spend' => '5']]], 200)]);

        $result = $this->service()->getInsights('tok', '123', 'ad', '2026-01-01', '2026-01-02');

        $this->assertSame('5', $result['spend'] ?? null);
        Http::assertSentCount(1); // sem retries desnecessários
    }
}
