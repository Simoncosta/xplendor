<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Services\Ai\AiPrompt;
use App\Services\Ai\AiSyncRequest;

/**
 * XPLENDOR — F1-3: sugestão por IA da categoria das famílias que as regras não
 * reconheceram (função "family_categories", com o modelo escolhido pelo root). Só sugere:
 * a equipa confirma. Fica registada em ai_requests, como as outras funções.
 */
class FamilyCategoryAiSuggester
{
    public const FUNCTION = 'family_categories';

    /**
     * @param  array<int, string>  $paths  caminhos das famílias ("Família \ Bebidas \ Sangria")
     * @return array<string, string>  caminho => chave de categoria (só as válidas)
     */
    public function suggest(int $companyId, ?int $userId, array $paths): array
    {
        $paths = array_values(array_unique(array_filter($paths, fn ($p) => trim((string) $p) !== '')));
        if ($paths === []) {
            return [];
        }

        $result = AiSyncRequest::run(self::FUNCTION, $this->prompt($paths), $companyId, $userId, ['input' => ['familias' => $paths]]);
        $out = [];
        foreach ((array) (($result->json ?? [])['familias'] ?? []) as $item) {
            $path = (string) ($item['familia'] ?? '');
            $key = (string) ($item['categoria'] ?? '');
            if (in_array($path, $paths, true) && FamilyCategoryRules::isValid($key)) {
                $out[$path] = $key;
            }
        }

        return $out;
    }

    /** @param  array<int, string>  $paths */
    public function prompt(array $paths): AiPrompt
    {
        $categories = implode("\n", array_map(fn ($k, $v) => "- {$k}: {$v}", array_keys(FamilyCategoryRules::CATEGORIES), FamilyCategoryRules::CATEGORIES));
        $system = "Classifica famílias de artigos do sistema de vendas de um restaurante em categorias de marketing.\n"
            . "Categorias possíveis (usa só a chave):\n{$categories}\n"
            . 'Regras: "entrega" é para famílias de plataformas de entrega; "excluir" é para famílias que não são vendas ao cliente '
            . '(consumo interno, refeições do pessoal, produção, taxas); se não houver uma categoria clara, usa "outros". '
            . 'Responde só em JSON, com uma entrada por família, mantendo o caminho exatamente como foi dado.';
        $user = "Famílias:\n" . implode("\n", $paths);
        $schema = [
            'type' => 'object',
            'properties' => [
                'familias' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'familia' => ['type' => 'string'],
                            'categoria' => ['type' => 'string', 'enum' => array_keys(FamilyCategoryRules::CATEGORIES)],
                        ],
                        'required' => ['familia', 'categoria'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['familias'],
            'additionalProperties' => false,
        ];

        return new AiPrompt($system, $user, [], $schema, 'categorias');
    }
}
