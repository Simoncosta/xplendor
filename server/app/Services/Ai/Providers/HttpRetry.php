<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Tentativas partilhadas pelos fornecedores: repete só em limite de pedidos (sem ser o limite
 * de gastos), sobrecarga ou falha do lado do fornecedor; um 4xx não se repete.
 */
trait HttpRetry
{
    /** @param callable(): Response $call */
    private function withRetries(callable $call, string $provider, array $retryStatuses): Response
    {
        $backoff = (array) config('ai.retry_backoff_ms', [800, 2000]);
        $attempts = count($backoff) + 1;
        $last = null;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $response = $call();
            } catch (ConnectionException $e) {
                $last = new AiProviderException('O fornecedor de IA não respondeu a tempo.', null, $provider, $e);
                if ($i < $attempts) {
                    usleep((int) $backoff[$i - 1] * 1000);
                    continue;
                }
                throw $last;
            }
            if ($response->successful()) {
                return $response;
            }
            $status = $response->status();
            $spendLimit = str_contains((string) $response->body(), 'spend_limit') || str_contains((string) $response->body(), 'credit_balance') || str_contains((string) $response->body(), 'usage_limit');
            if (in_array($status, $retryStatuses, true) && ! $spendLimit && $i < $attempts) {
                $wait = (int) ($response->header('retry-after') ?: 0);
                usleep(max((int) $backoff[$i - 1], min($wait, 5) * 1000) * 1000);
                continue;
            }
            $message = (string) ($response->json('error.message') ?? 'Erro do fornecedor de IA.');
            throw new AiProviderException(mb_substr($message, 0, 300), $status, $provider);
        }

        throw $last ?? new AiProviderException('O fornecedor de IA não respondeu.', null, $provider);
    }
}
