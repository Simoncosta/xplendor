<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * O modelo parou sem uma leitura utilizável: recusou (stop_reason "refusal") ou a resposta
 * ficou cortada (stop_reason "max_tokens"). A fatura fica para revisão manual, com o motivo,
 * sem novas tentativas (os tokens gastos contam no custo da fatura).
 */
class OcrModelStopped extends \RuntimeException
{
    public const REASONS = [
        'refusal' => 'a IA recusou ler esta fatura',
        'max_tokens' => 'a resposta da IA ficou cortada (a fatura é demasiado longa para o limite de resposta)',
    ];

    public function __construct(public readonly string $reason, public readonly int $tokensIn, public readonly int $tokensOut)
    {
        parent::__construct('Para revisão manual: ' . (self::REASONS[$reason] ?? "a IA parou ({$reason})") . '. Confirme e preencha as linhas à mão.');
    }
}
