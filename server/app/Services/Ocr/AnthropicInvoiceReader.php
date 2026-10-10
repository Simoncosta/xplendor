<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\Providers\HttpRetry;
use Illuminate\Support\Facades\Http;

/**
 * OCR das faturas na Anthropic (Messages API):
 *  · o ficheiro vai diretamente ao modelo: o PDF como documento (sem o converter) e as
 *    fotografias como imagem, sem citações;
 *  · a resposta usa saídas estruturadas (output_config.format, json_schema) com o esquema de
 *    hoje (OcrSchemas); o esforço vai em output_config.effort ("default" = não se envia);
 *  · stop_reason "refusal" ou "max_tokens": OcrModelStopped (revisão manual, sem repetir);
 *  · repete só em limite de pedidos, sobrecarga ou falha do fornecedor (HttpRetry).
 * A chave (ANTHROPIC_API_KEY) só existe no servidor.
 */
class AnthropicInvoiceReader
{
    use HttpRetry;

    /** Tipos de imagem que a Anthropic aceita; o resto lê-se como PDF ou falha antes de gastar. */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * @return array{content: string, tokens_in: int, tokens_out: int, input: string}
     */
    public function read(string $model, string $effort, string $system, array $schema, string $instruction, string $bytes, string $mime, bool $isPdf, int $maxTokens): array
    {
        $key = (string) config('ai.providers.anthropic.key');
        if ($key === '') {
            throw new AiProviderException('ANTHROPIC_API_KEY não configurada.', null, 'anthropic');
        }
        $mime = strtolower($mime) === 'image/jpg' ? 'image/jpeg' : strtolower($mime);
        if ($isPdf) {
            $file = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]];
        } elseif (in_array($mime, self::IMAGE_TYPES, true)) {
            $file = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];
        } else {
            throw new \RuntimeException("Tipo de ficheiro não suportado para a leitura: {$mime}.");
        }

        $outputConfig = ['format' => ['type' => 'json_schema', 'schema' => $schema]];
        if ($effort !== 'default' && $effort !== '') {
            $outputConfig['effort'] = $effort;
        }
        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            // O ficheiro antes da instrução (como a documentação recomenda para documentos).
            'messages' => [['role' => 'user', 'content' => [$file, ['type' => 'text', 'text' => $instruction]]]],
            'output_config' => $outputConfig,
        ];

        $response = $this->withRetries(fn () => Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => (string) config('ai.providers.anthropic.version', '2023-06-01'),
        ])->acceptJson()->connectTimeout(15)->timeout((int) config('services.openai.ocr.http_timeout', 240))
            ->post((string) config('ai.providers.anthropic.url'), $body), 'anthropic', [429, 500, 529]);

        $usage = (array) $response->json('usage', []);
        $in = (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0);
        $out = (int) ($usage['output_tokens'] ?? 0);
        $stop = (string) $response->json('stop_reason');
        if (in_array($stop, ['refusal', 'max_tokens'], true)) {
            throw new OcrModelStopped($stop, $in, $out);
        }
        $text = collect((array) $response->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        if (trim($text) === '') {
            throw new AiProviderException('A resposta da IA veio vazia.', $response->status(), 'anthropic');
        }

        return ['content' => $text, 'tokens_in' => $in, 'tokens_out' => $out, 'input' => $isPdf ? 'pdf' : 'imagem'];
    }
}
