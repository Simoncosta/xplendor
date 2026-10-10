<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Services\Ai\AiProviderException;

/**
 * Erro temporário da Anthropic ao ler uma fatura: sobrecarga (529), erro do lado do fornecedor
 * (5xx), limite de pedidos (429, sem ser o limite de gastos) ou sem resposta a tempo. A fatura
 * continua "a ler" e a fila repete com espera crescente (ProcessInvoiceOcrJob).
 */
class OcrTemporaryFailure extends AiProviderException
{
    /** Um estado HTTP é temporário? (null = sem resposta a tempo.) */
    public static function isTemporary(?int $status, string $body = ''): bool
    {
        if ($status === null) {
            return true;
        }
        if ($status === 429) {
            // O limite de gastos ou o saldo esgotado não passam com o tempo.
            return ! str_contains($body, 'spend_limit') && ! str_contains($body, 'credit_balance') && ! str_contains($body, 'usage_limit');
        }

        return $status === 529 || ($status >= 500 && $status <= 599);
    }
}
