<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Social\MetaSocialClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MetaSocialClient::pages(): a paginação segue o "next" da Meta tal como vem (com os parâmetros dele). Antes,
 * o pedido seguinte ia sem query e voltava a ler a primeira página (só se notava com mais de 100 Páginas).
 * Resposta simulada de duas páginas; nenhum pedido real.
 */
class MetaSocialClientPagingTest extends TestCase
{
    public function test_pages_follows_next_and_reads_the_second_page(): void
    {
        $next = MetaSocialClient::GRAPH_URL . '/me/accounts?fields=id%2Cname%2Caccess_token&limit=100&access_token=tok&after=CURSOR2';
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $r) use ($next) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            if (($q['after'] ?? null) === 'CURSOR2') {
                return Http::response(['data' => [['id' => '2', 'name' => 'Segunda', 'access_token' => 'p2']]]);
            }
            if (($q['access_token'] ?? null) !== 'tok') {
                return Http::response(['error' => ['message' => 'sem token', 'code' => 190]], 400);
            }

            return Http::response(['data' => [['id' => '1', 'name' => 'Primeira', 'access_token' => 'p1', 'instagram_business_account' => ['id' => '17', 'username' => 'um']]],
                'paging' => ['cursors' => ['after' => 'CURSOR2'], 'next' => $next]]);
        }]);

        $out = app(MetaSocialClient::class)->pages('tok');

        $this->assertTrue($out['ok']);
        $this->assertSame(['1', '2'], array_column($out['pages'], 'id'), 'a segunda página lê-se uma vez e a primeira não se repete');
        $this->assertSame(['um', null], [$out['pages'][0]['instagram']['username'], $out['pages'][1]['instagram']]);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->url() === $next);
    }
}
