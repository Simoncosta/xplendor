<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiPrompt;

/** Um fornecedor de IA. send() devolve o texto e o uso: input, cached_input, output, reasoning (null se o fornecedor não o separa). */
interface AiProvider
{
    /** @return array{text: string, usage: array{input: int, cached_input: int, output: int, reasoning: ?int}} */
    public function send(AiPrompt $prompt, string $model, string $effort, int $maxTokens): array;
}
