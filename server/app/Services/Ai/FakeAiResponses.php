<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * AI_FAKE (Bússola Master): respostas fixas e plausíveis por função, sem rede, para verificar
 * os ecrãs em dev sem gastar nem enviar dados a um fornecedor. Os tokens e o custo são
 * simulados com a tabela de preços (para os registos e os painéis terem números); o texto é
 * sempre marcado como simulado onde a função o permite. Nunca em produção (AiGateway::fake).
 */
class FakeAiResponses
{
    public const OUTPUT_TOKENS = 200;

    public static function result(string $function, AiPrompt $prompt, string $provider, string $model, string $effort): AiResult
    {
        [$text, $json] = self::answer($function, $prompt);
        $in = (int) ceil(mb_strlen($prompt->system . $prompt->text) / 4);
        $usage = [['input' => $in, 'output' => self::OUTPUT_TOKENS, 'cached_input' => 0]];

        return new AiResult($text, $json, $provider, $model, $effort, $in, self::OUTPUT_TOKENS, null,
            AiGateway::cost($model, $usage), PtPtChecker::issuesIn($json ?? $text), false, 0);
    }

    /** @return array{0: string, 1: ?array} */
    private static function answer(string $function, AiPrompt $prompt): array
    {
        if ($function === 'radar_relevancia') {
            // O prompt traz as tendências como JSON com "id"; todas avaliadas e relevantes, com pontuações diferentes
            // (o filtro, o desporto e os temas da marca continuam a decidir no encaixe).
            preg_match_all('/"id"\s*:\s*(\d+)/', $prompt->text, $m);
            $rows = array_map(fn ($id) => [
                'id' => (int) $id, 'relevante' => true, 'pontuacao' => 50 + ((int) $id * 7) % 45,
                'tema' => 'atualidade', 'frase' => 'Avaliação simulada para a verificação no ecrã.',
            ], array_values(array_unique($m[1])));
            $json = ['avaliacoes' => $rows];

            return [json_encode($json, JSON_UNESCAPED_UNICODE), $json];
        }
        if ($function === 'radar_porque') {
            return ['Tema em destaque em Portugal neste momento, próximo do que a sua marca costuma comunicar (frase simulada).', null];
        }
        if ($function === 'bussola_jogadas') {
            preg_match_all('/Chave:\s*(\S+)/u', $prompt->text, $m);
            $json = ['jogadas' => array_map(fn ($k) => ['chave' => $k, 'o_que' => 'Frase simulada para a verificação no ecrã, sem números nem causas.'], array_values(array_unique($m[1])))];

            return [json_encode($json, JSON_UNESCAPED_UNICODE), $json];
        }
        if ($prompt->schema || $prompt->json) {
            return ['{}', []];
        }

        return ['Texto simulado para a verificação no ecrã (AI_FAKE).', null];
    }
}
