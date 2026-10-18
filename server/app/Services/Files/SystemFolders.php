<?php

declare(strict_types=1);

namespace App\Services\Files;

/**
 * Ficheiros: as pastas do sistema e a regra de cada uma, num só sítio. Os Ficheiros nunca abrem
 * um ficheiro que a pessoa não pudesse abrir no módulo de origem: cada pasta exige o módulo e
 * TODAS as permissões indicadas; "no_agency" fecha-a à agência gestora (faturas e dados
 * pessoais de clientes). As pastas dos módulos desligados não aparecem.
 *
 * chave => [nome, módulo (null = base), permissões, no_agency, subpastas fixas (chave => nome)]
 */
final class SystemFolders
{
    public const ROOTS = [
        'editorial' => ['name' => 'Linha Editorial', 'module' => 'linha_editorial', 'permissions' => ['editorial.ver'], 'no_agency' => false],
        'ocr' => ['name' => 'Faturas de fornecedores', 'module' => 'restauracao_faturas', 'permissions' => ['restauracao.ver', 'financas.ver'], 'no_agency' => true],
        'xplendor' => ['name' => 'Faturação XPLENDOR', 'module' => null, 'permissions' => ['faturacao_xplendor.ver'], 'no_agency' => true,
            'children' => ['cobrancas' => 'Cobranças', 'comprovativos' => 'Comprovativos', 'pedidos' => 'Pedidos de suporte', 'orcamentos' => 'Orçamentos']],
        'suporte' => ['name' => 'Suporte', 'module' => null, 'permissions' => ['suporte.ver'], 'no_agency' => false],
        'satisfacao' => ['name' => 'Relatórios de satisfação', 'module' => 'aftersales', 'permissions' => ['automovel.ver'], 'no_agency' => true],
        'viaturas' => ['name' => 'Veículos', 'module' => 'stock', 'permissions' => ['automovel.ver'], 'no_agency' => false],
        'marca' => ['name' => 'Marca e logótipos', 'module' => null, 'permissions' => ['empresa.ver'], 'no_agency' => false],
        'blog' => ['name' => 'Blog', 'module' => null, 'permissions' => ['blog.ver'], 'no_agency' => false],
        'equipa' => ['name' => 'Equipa', 'module' => null, 'permissions' => ['utilizadores.ver'], 'no_agency' => false],
        'modelos' => ['name' => 'Modelos de documento', 'module' => 'documents', 'permissions' => ['automovel.ver'], 'no_agency' => false],
        // Site do consultor: as fotografias públicas dos imóveis e dos empreendimentos, e os ficheiros do perfil do site.
        'imoveis' => ['name' => 'Imóveis', 'module' => 'imoveis', 'permissions' => ['imoveis.ver'], 'no_agency' => false],
        'empreendimentos' => ['name' => 'Empreendimentos', 'module' => 'imoveis', 'permissions' => ['imoveis.ver'], 'no_agency' => false],
        'site_consultor' => ['name' => 'Site do consultor', 'module' => 'site_consultor', 'permissions' => ['site_consultor.ver'], 'no_agency' => false],
    ];

    /** A pasta de topo de uma chave ("editorial:2026-10" → "editorial"). */
    public static function root(string $systemKey): string
    {
        return explode(':', $systemKey, 2)[0];
    }

    public static function rule(string $systemKey): ?array
    {
        return self::ROOTS[self::root($systemKey)] ?? null;
    }
}
