<?php

declare(strict_types=1);

namespace App\Support;

/**
 * DMS — Caminho B: catálogo ÚNICO das variáveis dos modelos de documento.
 *
 * A MESMA definição alimenta (a) a lista mostrada ao stand no UI e (b) a
 * resolução dos valores na geração. Fonte: o "saco" (empresa + viatura +
 * cliente + venda) — NÃO fornecedor (decisão do Simon). Para acrescentar uma
 * variável, editar SÓ o array DEFS.
 *
 * Cada entrada: [chave, rótulo pt-PT, grupo, caminho no saco].
 */
class DocumentVariables
{
    private const DEFS = [
        // Empresa
        ['empresa_nome',            'Nome fiscal da empresa',   'Empresa',  'company.fiscal_name'],
        ['empresa_nome_comercial',  'Nome comercial',           'Empresa',  'company.trade_name'],
        ['empresa_nipc',            'NIPC',                     'Empresa',  'company.nipc'],
        ['empresa_morada',          'Morada da empresa',        'Empresa',  'company.address'],
        ['empresa_codigo_postal',   'Código postal da empresa', 'Empresa',  'company.postal_code'],
        ['empresa_localidade',      'Localidade da empresa',    'Empresa',  'company.locality'],
        ['empresa_telefone',        'Telefone da empresa',      'Empresa',  'company.phone'],
        ['empresa_telemovel',       'Telemóvel da empresa',     'Empresa',  'company.mobile'],
        ['empresa_email',           'Email da empresa',         'Empresa',  'company.email'],
        // Viatura
        ['viatura_marca',           'Marca',                    'Viatura',  'car.brand'],
        ['viatura_modelo',          'Modelo',                   'Viatura',  'car.model'],
        ['viatura_versao',          'Versão',                   'Viatura',  'car.version'],
        ['viatura_matricula',       'Matrícula',                'Viatura',  'car.license_plate'],
        ['viatura_mes_matricula',   'Mês de matrícula',         'Viatura',  'car.registration_month'],
        ['viatura_ano_matricula',   'Ano de matrícula',         'Viatura',  'car.registration_year'],
        // Cliente
        ['cliente_nome',            'Nome do cliente',          'Cliente',  'customer.name'],
        ['cliente_nif',             'NIF do cliente',           'Cliente',  'customer.nif'],
        ['cliente_telefone',        'Telefone do cliente',      'Cliente',  'customer.phone'],
        ['cliente_email',           'Email do cliente',         'Cliente',  'customer.email'],
        ['cliente_morada',          'Morada do cliente',        'Cliente',  'customer.address'],
        ['cliente_codigo_postal',   'Código postal do cliente', 'Cliente',  'customer.postal_code'],
        ['cliente_localidade',      'Localidade do cliente',    'Cliente',  'customer.locality'],
        ['cliente_cc_numero',       'Nº Cartão de Cidadão',     'Cliente',  'customer.citizen_card_number'],
        ['cliente_cc_validade',     'Validade do CC',           'Cliente',  'customer.citizen_card_validity'],
        ['cliente_data_nascimento', 'Data de nascimento',       'Cliente',  'customer.birth_date'],
        ['cliente_nacionalidade',   'Nacionalidade',            'Cliente',  'customer.nationality'],
        ['cliente_profissao',       'Profissão',                'Cliente',  'customer.profession'],
        ['cliente_estado_civil',    'Estado civil',             'Cliente',  'customer.marital_status'],
        // Venda
        ['venda_data',              'Data da venda',            'Venda',    'sale.sold_at'],
    ];

    /** Catálogo para o UI: [{key, label, group}]. */
    public static function catalog(): array
    {
        return array_map(
            fn ($d) => ['key' => $d[0], 'label' => $d[1], 'group' => $d[2]],
            self::DEFS
        );
    }

    /**
     * Resolve TODAS as chaves a partir do saco (shape do SaleDocumentDataResource).
     * Devolve chave => valor (string; null vira "").
     *
     * @return array<string, string>
     */
    public static function resolveFromData(array $data): array
    {
        $out = [];
        foreach (self::DEFS as [$key, , , $path]) {
            $out[$key] = self::walk($data, $path);
        }
        return $out;
    }

    private static function walk(array $data, string $path): string
    {
        $node = $data;
        foreach (explode('.', $path) as $seg) {
            if (is_array($node) && array_key_exists($seg, $node)) {
                $node = $node[$seg];
            } else {
                return '';
            }
        }
        return $node === null ? '' : (string) $node;
    }
}
