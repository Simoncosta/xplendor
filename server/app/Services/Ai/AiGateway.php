<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Providers\AiProvider;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\OpenAiProvider;

/**
 * A interface ÚNICA da IA (todas as funções, salvo o OCR): escolhe o fornecedor, o modelo e o
 * esforço da função (AiFunctionSettings), junta a instrução comum do português de Portugal,
 * envia, calcula o custo com os preços de config/ai.php e verifica o português: se o
 * verificador encontrar marcas do português do Brasil ou travessões, pede de novo UMA vez; se
 * persistirem, o resultado leva as marcas (o ecrã mostra "Rever o português").
 */
class AiGateway
{
    public function generate(string $function, AiPrompt $prompt): AiResult
    {
        $s = AiFunctionSettings::for($function);

        return $this->generateWith($function, $prompt, $s['model'], $s['effort']);
    }

    /** Com um modelo e esforço explícitos (o teste às cegas). */
    public function generateWith(string $function, AiPrompt $prompt, string $model, string $effort): AiResult
    {
        $provider = AiFunctionSettings::providerOf($model);
        $client = $this->client($provider);
        $maxTokens = (int) config("ai.functions.{$function}.max_tokens", 3000) + (int) config("ai.reasoning_headroom.{$effort}", 8000);
        $full = new AiPrompt(trim(config('ai.pt_pt_instruction') . "\n\n" . $prompt->system), $prompt->text, $prompt->images, $prompt->schema, $prompt->schemaName, $prompt->json);

        $started = microtime(true);
        $first = $client->send($full, $model, $effort, $maxTokens);
        $usage = [$first['usage']];
        [$text, $json] = $this->read($first['text'], $full);
        $issues = PtPtChecker::issuesIn($json ?? $text);
        $retried = false;

        if ($issues !== []) {
            $retried = true;
            $again = $full->withText($full->text . "\n\nResposta anterior, a corrigir:\n" . AiText::wrap($first['text']) . "\n\n" . PtPtChecker::retryInstruction($issues));
            $second = $client->send($again, $model, $effort, $maxTokens);
            $usage[] = $second['usage'];
            [$text, $json] = $this->read($second['text'], $full);
            $issues = PtPtChecker::issuesIn($json ?? $text);
        }

        $in = array_sum(array_column($usage, 'input'));
        $out = array_sum(array_column($usage, 'output'));
        $reasoning = in_array(null, array_column($usage, 'reasoning'), true) ? null : array_sum(array_column($usage, 'reasoning'));

        return new AiResult($text, $json, $provider, $model, $effort, $in, $out, $reasoning,
            self::cost($model, $usage), $issues, $retried, (int) round((microtime(true) - $started) * 1000));
    }

    /** Custo em USD com os preços do modelo (o raciocínio já vem nos tokens de saída). */
    public static function cost(string $model, array $usages): float
    {
        $p = (array) (AiFunctionSettings::model($model)['prices'] ?? []);
        $total = 0.0;
        foreach ($usages as $u) {
            $cached = (int) ($u['cached_input'] ?? 0);
            $total += (max(0, (int) $u['input'] - $cached) * (float) ($p['input'] ?? 0) + $cached * (float) ($p['cached_input'] ?? $p['input'] ?? 0)
                + (int) $u['output'] * (float) ($p['output'] ?? 0)) / 1_000_000;
        }

        return $total;
    }

    /** @return array{0: string, 1: ?array} */
    private function read(string $raw, AiPrompt $prompt): array
    {
        if (! $prompt->json && ! $prompt->schema) {
            return [trim($raw), null];
        }

        return [$raw, AiText::decodeJson($raw)];
    }

    private function client(string $provider): AiProvider
    {
        return match ($provider) {
            'anthropic' => app(AnthropicProvider::class),
            'openai' => app(OpenAiProvider::class),
            default => throw new AiProviderException("Fornecedor de IA desconhecido: {$provider}."),
        };
    }
}
