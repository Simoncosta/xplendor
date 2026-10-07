<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Os dois fornecedores de IA simulados (Anthropic Messages e OpenAI Responses), com as chaves
 * de teste e sem esperas entre tentativas. O modelo por omissão é o claude-opus-5-5.
 */
trait FakesAi
{
    protected function configureAi(): void
    {
        config(['ai.providers.anthropic.key' => 'test-anthropic', 'ai.providers.openai.key' => 'test-openai', 'ai.retry_backoff_ms' => [0, 0]]);
    }

    /** Resposta da Anthropic: blocos de raciocínio e de texto (escolhidos pelo tipo) e o uso. */
    protected function anthropicResponse(array|string $content, array $usage = ['input_tokens' => 1000, 'output_tokens' => 500], string $stop = 'end_turn')
    {
        return Http::response([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => $stop,
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'x'],
                ['type' => 'text', 'text' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE)],
            ],
            'usage' => $usage,
        ]);
    }

    /** Resposta da OpenAI (Responses API): raciocínio e mensagem com output_text, e o uso com os tokens de raciocínio. */
    protected function openAiResponse(array|string $content, array $usage = ['input_tokens' => 1000, 'output_tokens' => 500, 'output_tokens_details' => ['reasoning_tokens' => 200]], string $status = 'completed')
    {
        return Http::response([
            'id' => 'resp_test', 'object' => 'response', 'status' => $status, 'model' => 'gpt-6.1-sol',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE)]]],
            ],
            'usage' => $usage,
        ]);
    }

    /** Os dois fornecedores respondem com o mesmo conteúdo. */
    protected function fakeAi(array|string $content): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->anthropicResponse($content),
            'api.openai.com/v1/responses' => $this->openAiResponse($content),
        ]);
    }

    /** O pedido enviado (sistema + texto, ou só o texto), de qualquer um dos fornecedores. */
    protected function sentAiPrompt(bool $withSystem = true): string
    {
        $prompt = '';
        Http::assertSent(function (Request $r) use (&$prompt, $withSystem) {
            if (str_contains($r->url(), 'api.anthropic.com')) {
                $prompt = ($withSystem ? $r['system'] . "\n" : '') . collect($r['messages'][0]['content'])->where('type', 'text')->pluck('text')->implode("\n");

                return true;
            }
            if (str_contains($r->url(), 'api.openai.com/v1/responses')) {
                $prompt = ($withSystem ? $r['instructions'] . "\n" : '') . collect($r['input'][0]['content'])->where('type', 'input_text')->pluck('text')->implode("\n");

                return true;
            }

            return false;
        });

        return $prompt;
    }
}
