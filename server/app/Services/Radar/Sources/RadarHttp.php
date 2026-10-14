<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Radar: o cliente HTTP comum a todas as fontes. Tempo limite de 15 segundos (5 para ligar),
 * agente identificado, sem repetições automáticas (cada pedido conta para o teto de 4 por hora)
 * e no máximo 2 MB por resposta.
 */
class RadarHttp
{
    public const ACCEPT = 'application/rss+xml, application/xml, text/xml, application/json';

    /** @param array<string, string> $headers os cabeçalhos condicionais (If-None-Match, If-Modified-Since) */
    public function get(string $url, array $headers = []): RadarHttpResponse
    {
        $max = (int) config('radar.max_bytes', 2 * 1024 * 1024);
        try {
            $response = Http::timeout((int) config('radar.timeout', 15))
                ->connectTimeout((int) config('radar.connect_timeout', 5))
                ->withUserAgent((string) config('radar.user_agent'))
                ->accept(self::ACCEPT)
                ->withHeaders($headers)
                ->withOptions([
                    'allow_redirects' => ['max' => 3],
                    // Recusa logo uma resposta que anuncia mais do que o limite.
                    'on_headers' => function ($r) use ($max) {
                        if ((int) $r->getHeaderLine('Content-Length') > $max) {
                            throw new RadarFetchException('A resposta tem mais de 2 MB.');
                        }
                    },
                ])
                ->get($url);
        } catch (ConnectionException|RequestException|TransferException $e) {
            // O limite de 2 MB vem embrulhado pelo Guzzle e pelo Laravel: devolve-se a mensagem própria.
            for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
                if ($previous instanceof RadarFetchException) {
                    throw $previous;
                }
            }
            throw new RadarFetchException('Sem resposta da fonte (ligação recusada ou tempo esgotado).');
        }

        $body = $response->body();
        if (strlen($body) > $max) {
            throw new RadarFetchException('A resposta tem mais de 2 MB.');
        }

        return new RadarHttpResponse($response->status(), $body, $response->header('ETag') ?: null, $response->header('Last-Modified') ?: null);
    }
}
