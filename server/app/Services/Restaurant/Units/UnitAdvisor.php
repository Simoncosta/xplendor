<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Units;

use App\Services\Ai\AiGateway;
use App\Services\Ai\AiPrompt;
use App\Services\Ai\AiSyncRequest;

/**
 * XPLENDOR — Auditoria v2: "Perguntar à IA" num artigo por identificar (função unit_advisor, com o
 * modelo escolhido em Admin › Modelos de IA). Só vão dados do artigo: as descrições dos fornecedores
 * (sem o nome do fornecedor), a família, as unidades faturadas e os preços, e o uso nas fichas.
 * Nunca dados pessoais. A resposta é JSON validado e aparece sempre como "Sugestão da IA, a confirmar".
 */
final class UnitAdvisor
{
    public const FUNCTION = 'unit_advisor';

    private const TYPES = ['peso', 'volume', 'contavel', 'embalado_peso', 'embalado_inteiro'];

    /** Sem chave configurada (nem modo simulado), o botão fica desativado com a explicação. */
    public static function availability(): array
    {
        if (AiGateway::available(self::FUNCTION)) {
            return ['available' => true, 'reason' => null];
        }
        return ['available' => false, 'reason' => 'Indisponível: o serviço de sugestões não está configurado. Contacte o suporte da XPLENDOR.'];
    }

    /** Os dados que vão para a IA (e a assinatura, para a cache). */
    public static function payload(array $e, array $proposal): array
    {
        $data = [
            'descricao_do_artigo' => $e['description'],
            'familia' => $e['family'],
            'unidade_base_no_pingwin' => $e['base_unit'],
            'compras' => array_map(fn ($p) => [
                'descricoes_do_fornecedor' => $p['descriptions'], 'unidade_faturada' => $p['unit_label'], 'quantidade_12_meses' => round($p['qnt'], 3),
                'preco_por_unidade_faturada_eur' => $p['price_per_unit'], 'fator_no_pingwin' => $p['pingwin_factor'],
            ], array_slice($e['purchases'], 0, 6)),
            'uso_nas_fichas' => array_map(fn ($r) => ['quantidade' => $r['qnt'], 'unidade' => $r['unit']], array_slice($e['recipes'], 0, 8)),
            'vendido_um_para_um' => (bool) ($e['sold_one_to_one'] ?? false),
            'preco_mediano_da_familia_por_unidade_base_eur' => $e['family_median'],
            'contradicoes' => $proposal['conflicts'],
            'mais_informacao' => $e['extra_descriptions'] ?? [],
        ];

        return [$data, sha1((string) json_encode($data))];
    }

    /** Pergunta à IA (ou devolve a resposta guardada para os mesmos dados). */
    public static function advise(int $companyId, ?int $userId, array $e, array $proposal, ?array $cached = null, ?string $cachedSignature = null): array
    {
        [$data, $signature] = self::payload($e, $proposal);
        if ($cached && $cachedSignature === $signature) {
            return $cached + ['cached' => true];
        }
        $system = 'Ajuda um restaurante a decidir as unidades de um artigo de stock: o tipo (peso, volume, contável, embalado usado ao peso, '
            . 'embalado usado inteiro), a embalagem em que chega, a unidade base para as fichas e para o stock, e quanto leva cada unidade de compra. '
            . 'Decide pelo uso nas fichas, depois pelas descrições dos fornecedores, depois pela unidade e pelo preço faturados; nunca pelo nome do '
            . 'artigo sozinho. Se faltar informação, diga-o na justificação e baixe a confiança. Responda só com o JSON pedido.';
        $schema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['tipo', 'embalagem', 'base', 'nome_base', 'unidades_compra', 'confianca', 'justificacao'],
            'properties' => [
                'tipo' => ['type' => 'string', 'enum' => self::TYPES],
                'embalagem' => ['type' => 'string'],
                'base' => ['type' => 'string', 'enum' => ['kg', 'L', 'un']],
                'nome_base' => ['type' => 'string'],
                'unidades_compra' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['unidade', 'fator'],
                    'properties' => ['unidade' => ['type' => 'string'], 'fator' => ['type' => 'number']]]],
                'confianca' => ['type' => 'number'],
                'justificacao' => ['type' => 'string'],
            ],
        ];
        $result = AiSyncRequest::run(self::FUNCTION, new AiPrompt($system, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), [], $schema, 'unidades'),
            $companyId, $userId, ['input' => ['artigo' => $e['product_id']]]);

        return self::validate((array) $result->json) + ['signature' => $signature, 'model' => $result->model, 'at' => now()->toIso8601String(), 'cached' => false];
    }

    /** A resposta validada: o que não respeita o esquema é recusado (nunca se mostra meio certo). */
    public static function validate(array $j): array
    {
        $type = (string) ($j['tipo'] ?? '');
        $base = (string) ($j['base'] ?? '');
        if (! in_array($type, self::TYPES, true) || ! in_array($base, ['kg', 'L', 'un'], true)) {
            throw new \RuntimeException('A sugestão veio incompleta: tente novamente ou dê mais informação.');
        }
        $units = [];
        foreach ((array) ($j['unidades_compra'] ?? []) as $u) {
            $f = (float) ($u['fator'] ?? 0);
            $name = trim((string) ($u['unidade'] ?? ''));
            if ($name !== '' && $f > 0) {
                $units[] = ['unit' => mb_substr($name, 0, 80), 'factor' => round($f, 6)];
            }
        }

        return [
            'type' => $type, 'type_label' => UnitProposal::typeLabel($type), 'packaging' => mb_substr(trim((string) ($j['embalagem'] ?? '')), 0, 120),
            'base' => $base, 'base_name' => mb_substr(trim((string) ($j['nome_base'] ?? '')), 0, 40) ?: UnitProposal::baseLabel($base),
            'purchase_units' => array_slice($units, 0, 6),
            'confidence' => round(max(0.0, min(1.0, (float) ($j['confianca'] ?? 0))), 2),
            'reason' => mb_substr(trim((string) ($j['justificacao'] ?? '')), 0, 400),
            'label' => 'Sugestão automática, a confirmar',
        ];
    }
}
