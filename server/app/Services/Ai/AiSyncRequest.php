<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiRequest;

/**
 * As funções síncronas (descrição e análise de viaturas): passam pela mesma interface única e
 * ficam registadas em ai_requests com o fornecedor, o modelo, os tokens, o custo e o
 * resultado do verificador do português, como as outras.
 */
class AiSyncRequest
{
    public static function run(string $function, AiPrompt $prompt, int $companyId, ?int $userId = null, array $extra = []): AiResult
    {
        $settings = AiFunctionSettings::for($function);
        $request = AiRequest::create($extra + [
            'company_id' => $companyId, 'user_id' => $userId, 'mode' => $function, 'status' => AiRequest::PROCESSING,
            'model' => $settings['model'], 'provider' => $settings['provider'], 'effort' => $settings['effort'], 'prompt_version' => "{$function}-v1",
        ]);
        try {
            $result = app(AiGateway::class)->generate($function, $prompt);
            $request->update($result->requestFields() + ['status' => AiRequest::DONE, 'result' => ['text' => mb_substr($result->text, 0, 20000)]]);

            return $result;
        } catch (\Throwable $e) {
            $request->update(['status' => AiRequest::ERROR, 'error_message' => AiRequestLifecycle::failureMessage($e),
                'provider_status' => $e instanceof AiProviderException ? $e->status : null]);
            throw $e;
        }
    }
}
