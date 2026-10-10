<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * Os esquemas JSON das respostas do OCR, os MESMOS dos prompts de hoje (InvoiceOcrService::prompt
 * e ::linesPrompt), para as saídas estruturadas da Anthropic (output_config.format, json_schema).
 *
 * Limites da API (confirmados na comparação de 10/10/2026): no máximo 16 parâmetros com tipos
 * em união (["string", "null"] ou anyOf) e 24 opcionais. Por isso não há tipos anuláveis: o
 * que a IA não souber fica de fora (campo opcional), como os prompts já pedem ("omite-o"), e a
 * sanitização trata a falta como hoje. Obrigatório só o que existe sempre (a descrição e o
 * total de cada linha, as listas e os blocos).
 */
final class OcrSchemas
{
    /** Máximo de campos opcionais aceite pela API. */
    public const MAX_OPTIONAL = 24;

    private const STR = ['type' => 'string'];
    private const NUM = ['type' => 'number'];

    /** Uma linha de artigo (os dois caminhos): 6 opcionais. */
    private static function line(): array
    {
        return self::object(['codigo' => self::STR, 'item' => self::STR, 'quantidade' => self::NUM, 'unidade' => self::STR,
            'precoUnitario' => self::NUM, 'descontoPct' => self::NUM, 'totalLinha' => self::NUM, 'taxaIva' => self::NUM], ['item', 'totalLinha']);
    }

    /** Sem QR: a fatura completa (cabeçalho, linhas e sumário). */
    public static function full(): array
    {
        return self::object([
            'fornecedor' => self::object(['nome' => self::STR, 'nif' => self::STR], ['nome']),
            'numeroFatura' => self::STR,
            'dataEmissao' => self::STR,
            'linhas' => ['type' => 'array', 'items' => self::line()],
            'sumario' => self::object([
                'totalMercadorias' => self::NUM, 'descontoComercial' => self::NUM, 'baseTributavel' => self::NUM,
                'ivaPorTaxa' => ['type' => 'array', 'items' => self::object(['taxa' => self::NUM, 'base' => self::NUM, 'iva' => self::NUM], ['taxa', 'base', 'iva'])],
                'valorIvaTotal' => self::NUM, 'retencaoFonte' => self::NUM, 'descontoFinanceiro' => self::NUM, 'total' => self::NUM,
            ], ['ivaPorTaxa']),
        ], ['fornecedor', 'linhas', 'sumario']);
    }

    /** Com QR: só as linhas, o nome do fornecedor e as guias (o cabeçalho e os totais vêm do QR). */
    public static function lines(): array
    {
        return self::object([
            'fornecedorNome' => self::STR,
            'linhas' => ['type' => 'array', 'items' => self::line()],
            'guias' => ['type' => 'array', 'items' => self::object(['numero' => self::STR, 'data' => self::STR], ['numero'])],
        ], ['linhas', 'guias']);
    }

    /** Os campos opcionais de um esquema (contados como a API: cada propriedade fora de "required"). */
    public static function optionalCount(array $schema): int
    {
        $n = 0;
        if (($schema['type'] ?? null) === 'object') {
            $n += count(array_diff(array_keys($schema['properties'] ?? []), $schema['required'] ?? []));
            foreach ($schema['properties'] ?? [] as $p) {
                $n += self::optionalCount($p);
            }
        }
        if (($schema['type'] ?? null) === 'array') {
            $n += self::optionalCount($schema['items'] ?? []);
        }

        return $n;
    }

    private static function object(array $properties, array $required): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => $required];
    }
}
