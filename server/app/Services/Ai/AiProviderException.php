<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** Erro de um fornecedor de IA. $status é o estado HTTP quando o fornecedor respondeu (conta no limite mensal). */
class AiProviderException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly string $provider = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
