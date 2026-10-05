<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente da OpenAI partilhado pelos modos de IA (blog, perfil da marca, criativos):
 * resposta em JSON, tentativas com pausa só em limite de pedidos ou falha do lado da
 * OpenAI, e os tokens gastos. Nos testes a OpenAI é sempre simulada (Http::fake).
 */
class OpenAiChat
{
    private const URL = 'https://api.openai.com/v1/chat/completions';
    private const TIMEOUT = 120;
    private const CONNECT_TIMEOUT = 15;
    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_MS = [500, 1500];

    /** @return array{content: string, usage: array} */
    public function call(array $messages, string $model, int $maxTokens = 4000, float $temperature = 0.4): array
    {
        $apiKey = (string) config('services.openai.key');
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_KEY não configurada.');
        }

        $last = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withToken($apiKey)
                    ->connectTimeout(self::CONNECT_TIMEOUT)
                    ->timeout(self::TIMEOUT)
                    ->acceptJson()
                    ->post(self::URL, [
                        'model'           => $model,
                        'temperature'     => $temperature,
                        'max_tokens'      => $maxTokens,
                        'response_format' => ['type' => 'json_object'],
                        'messages'        => $messages,
                    ]);

                if ($response->failed()) {
                    // Só vale a pena repetir em limite de pedidos ou falha do lado da OpenAI.
                    if (in_array($response->status(), [429, 500, 502, 503, 504], true) && $attempt < self::MAX_ATTEMPTS) {
                        usleep(self::BACKOFF_MS[$attempt - 1] * 1000);
                        continue;
                    }
                    $response->throw();
                }

                $content = $response->json('choices.0.message.content');
                if (! is_string($content) || trim($content) === '') {
                    throw new \RuntimeException('OpenAI devolveu conteúdo vazio.');
                }

                return ['content' => $content, 'usage' => (array) $response->json('usage', [])];
            } catch (RequestException $e) {
                throw $e; // 4xx: não repetir
            } catch (\Throwable $e) {
                $last = $e;
                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep(self::BACKOFF_MS[$attempt - 1] * 1000);
                }
            }
        }

        throw new \RuntimeException('OpenAI indisponível.', previous: $last);
    }
}
