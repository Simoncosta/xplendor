<?php

declare(strict_types=1);

namespace App\Services\Ga4;

/**
 * XPLENDOR — Redação de segredos em mensagens de erro do GA4. O SDK do Google, ao
 * falhar a carregar a credencial, ECOA o keyfile inteiro na mensagem — incluindo
 * a private_key. Antes de registar/mostrar qualquer erro, passar por aqui para
 * NÃO vazar a chave para logs nem para o frontend.
 */
class Ga4Redact
{
    public static function message(string $message): string
    {
        // Remove blocos PEM da chave privada.
        $m = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', '[REDACTED PRIVATE KEY]', $message) ?? $message;
        // Remove o valor de "private_key":"..." (caso venha escapado em JSON).
        $m = preg_replace('/"private_key"\s*:\s*"[^"]*"/', '"private_key":"[REDACTED]"', $m) ?? $m;

        // Evita despejar keyfiles enormes: se ainda for muito longo, trunca.
        if (mb_strlen($m) > 600) {
            $m = mb_substr($m, 0, 600) . '… [truncado]';
        }

        return $m;
    }
}
