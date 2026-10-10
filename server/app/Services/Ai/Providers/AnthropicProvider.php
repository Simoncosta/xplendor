<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiPrompt;
use App\Services\Ai\AiProviderException;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic (Messages API). Segundo a documentação atual (Claude Opus 5.5 e Sonnet 5.5):
 *  · raciocínio sempre ligado; o esforço vai em output_config.effort (nunca thinking.disabled
 *    nem budget_tokens); sem temperature, sem resposta pré-preenchida e sem ferramenta forçada;
 *  · saída estruturada por output_config.format (json_schema), sem cabeçalho beta;
 *  · imagens em base64 (bloco "image"); os blocos escolhem-se pelo tipo (o raciocínio vem antes);
 *  · o raciocínio é cobrado como saída e não vem separado no uso.
 * A chave (ANTHROPIC_API_KEY) só existe no servidor.
 */
class AnthropicProvider implements AiProvider
{
    use HttpRetry;

    public function send(AiPrompt $prompt, string $model, string $effort, int $maxTokens): array
    {
        $key = (string) config('ai.providers.anthropic.key');
        if ($key === '') {
            throw new AiProviderException('ANTHROPIC_API_KEY não configurada.', null, 'anthropic');
        }

        $content = [];
        foreach ($prompt->images as $img) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['media_type'], 'data' => $img['data']]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt->text];

        // "default": não se envia o esforço (vale o do modelo).
        $outputConfig = $effort === 'default' ? [] : ['effort' => $effort];
        if ($prompt->schema) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $prompt->schema];
        }
        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            // JSON sem esquema: a Anthropic não tem um modo "json_object"; a instrução pede só o objeto.
            'system' => $prompt->json && ! $prompt->schema ? $prompt->system . "\n\nResponda apenas com um objeto JSON válido, sem texto antes ou depois." : $prompt->system,
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
        if ($outputConfig !== []) {
            $body['output_config'] = $outputConfig;
        }

        $response = $this->withRetries(fn () => Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => (string) config('ai.providers.anthropic.version', '2023-06-01'),
        ])->acceptJson()->connectTimeout(15)->timeout(240)->post((string) config('ai.providers.anthropic.url'), $body), 'anthropic', [429, 500, 529]);

        $stop = (string) $response->json('stop_reason');
        if ($stop === 'refusal') {
            throw new AiProviderException('O modelo recusou o pedido.', $response->status(), 'anthropic');
        }
        $text = collect((array) $response->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        if ($stop === 'max_tokens' || trim($text) === '') {
            throw new AiProviderException('A resposta da IA veio incompleta (vazia ou cortada).', $response->status(), 'anthropic');
        }
        $usage = (array) $response->json('usage', []);

        return ['text' => $text, 'usage' => [
            'input' => (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cached_input' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'output' => (int) ($usage['output_tokens'] ?? 0),
            'reasoning' => null, // incluído nos tokens de saída
        ]];
    }
}
