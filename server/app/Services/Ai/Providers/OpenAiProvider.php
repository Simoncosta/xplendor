<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiPrompt;
use App\Services\Ai\AiProviderException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI (Responses API, a recomendada para integrações novas): instructions + input, o
 * esforço em reasoning.effort, a saída estruturada em text.format (json_schema, strict), as
 * imagens como input_image (data URL). O uso traz os tokens de raciocínio à parte
 * (output_tokens_details.reasoning_tokens), cobrados como saída. Sem guardar a resposta (store: false).
 */
class OpenAiProvider implements AiProvider
{
    use HttpRetry;

    public function send(AiPrompt $prompt, string $model, string $effort, int $maxTokens): array
    {
        $key = (string) config('ai.providers.openai.key');
        if ($key === '') {
            throw new AiProviderException('OPENAI_KEY não configurada.', null, 'openai');
        }

        // As imagens antes do texto (como na Anthropic).
        $content = [];
        foreach ($prompt->images as $img) {
            $content[] = ['type' => 'input_image', 'image_url' => "data:{$img['media_type']};base64,{$img['data']}"];
        }
        $content[] = ['type' => 'input_text', 'text' => $prompt->text];
        $body = [
            'model' => $model,
            'instructions' => $prompt->system,
            'input' => [['role' => 'user', 'content' => $content]],
            'reasoning' => ['effort' => $effort],
            'max_output_tokens' => $maxTokens,
            'store' => false,
        ];
        if ($prompt->schema) {
            $body['text'] = ['format' => ['type' => 'json_schema', 'name' => $prompt->schemaName, 'schema' => $prompt->schema, 'strict' => true]];
        } elseif ($prompt->json) {
            $body['text'] = ['format' => ['type' => 'json_object']];
        }

        $response = $this->withRetries(fn () => Http::withToken($key)->acceptJson()->connectTimeout(15)->timeout(240)
            ->post((string) config('ai.providers.openai.url'), $body), 'openai', [429, 500, 503]);

        $parts = collect((array) $response->json('output', []))->where('type', 'message')->flatMap(fn ($m) => (array) ($m['content'] ?? []));
        if ($parts->contains(fn ($c) => ($c['type'] ?? '') === 'refusal')) {
            throw new AiProviderException('O modelo recusou o pedido.', $response->status(), 'openai');
        }
        $text = $parts->where('type', 'output_text')->pluck('text')->implode('');
        if ($response->json('status') === 'incomplete' || trim($text) === '') {
            throw new AiProviderException('A resposta da IA veio incompleta (vazia ou cortada).', $response->status(), 'openai');
        }
        $usage = (array) $response->json('usage', []);

        return ['text' => $text, 'usage' => [
            'input' => (int) ($usage['input_tokens'] ?? 0),
            'cached_input' => (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0),
            'output' => (int) ($usage['output_tokens'] ?? 0),
            'reasoning' => isset($usage['output_tokens_details']['reasoning_tokens']) ? (int) $usage['output_tokens_details']['reasoning_tokens'] : null,
        ]];
    }
}
