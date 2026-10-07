<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** A resposta da IA já verificada: texto, JSON (se pedido), uso, custo e o resultado do verificador do português. */
final class AiResult
{
    public function __construct(
        public readonly string $text,
        public readonly ?array $json,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $effort,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly ?int $reasoningTokens,
        public readonly float $costUsd,
        public readonly array $ptIssues,
        public readonly bool $ptRetried,
        public readonly int $ms,
    ) {}

    /** As colunas de ai_requests (custos, tokens e verificador). */
    public function requestFields(): array
    {
        return [
            'provider' => $this->provider, 'model' => $this->model, 'effort' => $this->effort,
            'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens, 'reasoning_tokens' => $this->reasoningTokens,
            'cost_usd' => round($this->costUsd, 6),
            'prompt_tokens' => $this->inputTokens, 'completion_tokens' => $this->outputTokens, 'total_tokens' => $this->inputTokens + $this->outputTokens,
            'pt_issues' => $this->ptIssues ?: null, 'pt_retried' => $this->ptRetried, 'provider_status' => null,
        ];
    }
}
