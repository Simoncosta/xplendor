<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * Os esquemas JSON das respostas do OCR, os MESMOS dos prompts de hoje (InvoiceOcrService::prompt
 * e ::linesPrompt), para as saídas estruturadas da Anthropic (output_config.format, json_schema).
 * Todos os campos são obrigatórios e aceitam null: o que a IA não souber vem a null (nunca
 * inventado), e a sanitização trata-o como hoje.
 */
final class OcrSchemas
{
    private const STR = ['type' => ['string', 'null']];
    private const NUM = ['type' => ['number', 'null']];

    /** Uma linha de artigo (os dois caminhos). */
    private static function line(): array
    {
        return self::object(['codigo' => self::STR, 'item' => self::STR, 'quantidade' => self::NUM, 'unidade' => self::STR,
            'precoUnitario' => self::NUM, 'descontoPct' => self::NUM, 'totalLinha' => self::NUM, 'taxaIva' => self::NUM]);
    }

    /** Sem QR: a fatura completa (cabeçalho, linhas e sumário). */
    public static function full(): array
    {
        return self::object([
            'fornecedor' => self::object(['nome' => self::STR, 'nif' => self::STR]),
            'numeroFatura' => self::STR,
            'dataEmissao' => self::STR,
            'linhas' => ['type' => 'array', 'items' => self::line()],
            'sumario' => self::object([
                'totalMercadorias' => self::NUM, 'descontoComercial' => self::NUM, 'baseTributavel' => self::NUM,
                'ivaPorTaxa' => ['type' => 'array', 'items' => self::object(['taxa' => self::NUM, 'base' => self::NUM, 'iva' => self::NUM])],
                'valorIvaTotal' => self::NUM, 'retencaoFonte' => self::NUM, 'descontoFinanceiro' => self::NUM, 'total' => self::NUM,
            ]),
        ]);
    }

    /** Com QR: só as linhas, o nome do fornecedor e as guias (o cabeçalho e os totais vêm do QR). */
    public static function lines(): array
    {
        return self::object([
            'fornecedorNome' => self::STR,
            'linhas' => ['type' => 'array', 'items' => self::line()],
            'guias' => ['type' => 'array', 'items' => self::object(['numero' => self::STR, 'data' => self::STR])],
        ]);
    }

    private static function object(array $properties): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties)];
    }
}
