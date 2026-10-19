<?php

declare(strict_types=1);

namespace App\Modules;

/**
 * XPLENDOR — Registo CENTRAL dos módulos ativáveis por empresa. FONTE ÚNICA:
 * a lista de módulos, se são específicos-de-carros ou transversais, a teia de
 * DEPENDÊNCIAS (que módulo precisa de que módulo) e os PRESETS por ramo.
 *
 * Incremento 1 = só a estrutura. O esconder de secções (menu/rotas) e o gate de
 * segurança nas rotas leem daqui nos incrementos 2 e 3.
 *
 * ⚠️ Nota de mapeamento (do spike): a lista confirmada agrupa Leads/Vendas dentro
 * de "Comercial/CRM"; por isso a cadeia de dependências vive ao nível dos módulos:
 *   aftersales → commercial_crm → stock
 * (Pós-venda precisa da venda/lead, que é sobre uma viatura → precisa do stock.)
 */
class ModuleRegistry
{
    /**
     * module_key => [label, car_specific, depends_on[] (dependências DIRETAS)]
     */
    public const MODULES = [
        'stock' => [
            'label' => 'Stock de Veículos',
            'car_specific' => true,
            'depends_on' => [],
        ],
        'commercial_crm' => [
            'label' => 'Comercial / CRM',
            'car_specific' => true,
            'depends_on' => ['stock'],
        ],
        'finance' => [
            'label' => 'Finanças',
            'car_specific' => false,
            'depends_on' => [],
        ],
        'documents' => [
            'label' => 'Documentos',
            'car_specific' => false,
            'depends_on' => [],
        ],
        'aftersales' => [
            'label' => 'Pós-venda',
            'car_specific' => true,
            'depends_on' => ['commercial_crm'],
        ],
        'marketing_analytics' => [
            'label' => 'Análise de Marketing',
            'car_specific' => false,
            'depends_on' => [],
        ],
        'support_tasks' => [
            'label' => 'Suporte / Tarefas',
            'car_specific' => false,
            'depends_on' => [],
        ],
        // Linha Editorial — calendário de âncoras de conteúdo. TRANSVERSAL (serve
        // qualquer ramo, não é dos carros nem do PingWin) e sem dependências.
        'linha_editorial' => [
            'label' => 'Linha Editorial',
            'car_specific' => false,
            'depends_on' => [],
        ],
        // Restauração — PingWin (POS GrupoPIE). Específico do ramo restauração
        // (não é dos carros). É o "umbrella" da integração: todas as secções de
        // restauração DEPENDEM dele (a fronteira de segurança no backend continua
        // a ser ensure_module:pingwin).
        // Ficheiros: o gestor de ficheiros da empresa. Nasce desligado em todas (fora dos presets).
        'ficheiros' => [
            'label' => 'Ficheiros',
            'car_specific' => false,
            'depends_on' => [],
        ],
        // Imobiliário (Bússola Master, R3): a carteira de imóveis e a Bússola do imobiliário.
        // Nasce desligado; liga-se pelo preset real_estate (ramo Imobiliário) ou à mão.
        'imoveis' => [
            'label' => 'Imobiliário: Imóveis',
            'car_specific' => false,
            'depends_on' => [],
        ],
        // Site do consultor imobiliário: o site público do consultor (perfil, imóveis publicados,
        // empreendimentos, contactos). Nasce desligado em todas as empresas (fora dos presets);
        // só o root o liga.
        'site_consultor' => [
            'label' => 'Imobiliário: Site do consultor',
            'car_specific' => false,
            'depends_on' => ['imoveis'],
        ],
        // Radar de oportunidade (Bússola Master): o interruptor por empresa. Nasce desligado em
        // todas (fora dos presets); só o root o liga. Sem RADAR_ENABLED nada chega à empresa.
        'radar' => [
            'label' => 'Radar de oportunidade',
            'car_specific' => false,
            'depends_on' => [],
        ],
        'pingwin' => [
            'label' => 'PingWin (POS)',
            'car_specific' => false,
            'depends_on' => [],
        ],

        // ── Restauração › OPERAÇÃO (dia-a-dia). Cada secção é o seu módulo (o root
        //    liga/desliga à vontade); todas dependem de 'pingwin'. ──────────────
        'restauracao_lojas' => [
            'label' => 'Restauração: Lojas',
            'car_specific' => false,
            'depends_on' => ['pingwin'],
        ],
        'restauracao_calendario' => [
            'label' => 'Restauração: Calendário de Faturação',
            'car_specific' => false,
            'depends_on' => ['pingwin'],
        ],
        'restauracao_artigos' => [
            'label' => 'Restauração: Artigos',
            'car_specific' => false,
            'depends_on' => ['pingwin'],
        ],
        'restauracao_faturas' => [
            'label' => 'Restauração: Faturas',
            'car_specific' => false,
            'depends_on' => ['pingwin'],
        ],

        // ── Restauração › CADASTROS (base/registos). ──────────────────────────
        'restauracao_documentos' => [
            'label' => 'Cadastros: Documentos',
            'car_specific' => false,
            'depends_on' => ['pingwin'],
        ],
        'restauracao_familias' => [
            'label' => 'Cadastros: Famílias',
            'car_specific' => false,
            'depends_on' => ['pingwin'],
        ],
        'restauracao_fornecedores' => [
            'label' => 'Cadastros: Fornecedores',
            'car_specific' => false,
            'depends_on' => ['pingwin'],
        ],
        'restauracao_unidades' => [
            'label' => 'Cadastros: Unidades',
            'car_specific' => false,
            'depends_on' => ['pingwin'],
        ],
        'restauracao_condicoes_pagamento' => [
            'label' => 'Cadastros: Condições de Pagamento',
            'car_specific' => false,
            'depends_on' => ['pingwin'],
        ],
        // S2 — Conta corrente dos fornecedores (saldo real vs saldo PingWin, extrato).
        'restauracao_conta_corrente' => [
            'label' => 'Restauração: Conta Corrente Fornecedores',
            'car_specific' => false,
            'depends_on' => ['pingwin'],
        ],
        // Finanças › Mês: resultado e tesouraria do mês (documentos de fornecedor, conta corrente e vendas).
        'financas_mes' => [
            'label' => 'Finanças: Mês',
            'car_specific' => false,
            'depends_on' => ['pingwin', 'restauracao_conta_corrente'],
        ],
    ];

    /** As 10 secções de restauração (operação + cadastros), todas sob 'pingwin'. */
    public const RESTAURANT_SECTIONS = [
        'restauracao_lojas', 'restauracao_calendario', 'restauracao_artigos', 'restauracao_faturas',
        'restauracao_documentos', 'restauracao_familias', 'restauracao_fornecedores', 'restauracao_unidades',
        'restauracao_condicoes_pagamento', 'restauracao_conta_corrente',
    ];

    /** Presets por ramo: um atalho que liga um conjunto (ajustável depois). */
    public const PRESETS = [
        'automotive' => ['stock', 'commercial_crm', 'finance', 'documents', 'aftersales', 'marketing_analytics', 'support_tasks'],
        // Outros ramos (e empresas sem ramo): marketing, Linha Editorial e suporte.
        'base' => ['marketing_analytics', 'support_tasks', 'linha_editorial'],
        // Restauração liberta o PingWin + TODAS as secções (operação + cadastros).
        // Imobiliário (Bússola Master): marketing, suporte, Linha Editorial e os imóveis.
        'real_estate' => ['marketing_analytics', 'support_tasks', 'linha_editorial', 'imoveis'],
        'restaurant' => [
            'marketing_analytics', 'support_tasks', 'pingwin',
            'restauracao_lojas', 'restauracao_calendario', 'restauracao_artigos', 'restauracao_faturas',
            'restauracao_documentos', 'restauracao_familias', 'restauracao_fornecedores', 'restauracao_unidades',
            'restauracao_condicoes_pagamento', 'restauracao_conta_corrente', 'financas_mes',
        ],
    ];

    /** Ramo (raiz da árvore de setores) → preset. */
    private const SECTOR_PRESETS = ['automovel' => 'automotive', 'restauracao' => 'restaurant', 'imobiliario' => 'real_estate'];

    /**
     * O preset de uma empresa nova, pelo RAMO: o setor ou um antepassado dele decide
     * (Automóvel → automotive; Restauração → restaurant); outro ramo ou sem ramo → base.
     */
    public static function presetForSector(?int $sectorId): string
    {
        $sector = $sectorId ? \App\Models\ContentSector::find($sectorId) : null;
        foreach ($sector ? $sector->pathFromRoot() : [] as $node) {
            if (isset(self::SECTOR_PRESETS[$node->slug])) {
                return self::SECTOR_PRESETS[$node->slug];
            }
        }

        return 'base';
    }

    /**
     * O ramo de uma empresa (o slug do setor ou do antepassado que decide o preset: restauracao,
     * automovel, imobiliario), ou null. Para o menu mostrar o que é de cada ramo.
     */
    public static function branchForSector(?int $sectorId): ?string
    {
        $sector = $sectorId ? \App\Models\ContentSector::find($sectorId) : null;
        foreach ($sector ? $sector->pathFromRoot() : [] as $node) {
            if (isset(self::SECTOR_PRESETS[$node->slug])) {
                return (string) $node->slug;
            }
        }

        return null;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }

    public static function exists(string $key): bool
    {
        return isset(self::MODULES[$key]);
    }

    public static function label(string $key): string
    {
        return self::MODULES[$key]['label'] ?? $key;
    }

    /** Dependências DIRETAS de um módulo. @return string[] */
    public static function directDependencies(string $key): array
    {
        return self::MODULES[$key]['depends_on'] ?? [];
    }

    /** Dependências TRANSITIVAS (toda a cadeia ascendente). @return string[] */
    public static function dependencies(string $key): array
    {
        $out = [];
        foreach (self::directDependencies($key) as $dep) {
            $out[$dep] = true;
            foreach (self::dependencies($dep) as $deeper) {
                $out[$deeper] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Módulos que dependem (TRANSITIVAMENTE) de $key — a cadeia descendente.
     * É isto que impede desligar $key se algum destes estiver ativo.
     * @return string[]
     */
    public static function dependents(string $key): array
    {
        $out = [];
        foreach (self::keys() as $candidate) {
            if ($candidate === $key) {
                continue;
            }
            if (in_array($key, self::dependencies($candidate), true)) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /** @return string[] chaves do preset, já fechadas sobre dependências. */
    public static function presetKeys(string $preset): array
    {
        $keys = self::PRESETS[$preset] ?? [];
        $closed = [];
        foreach ($keys as $k) {
            $closed[$k] = true;
            foreach (self::dependencies($k) as $dep) {
                $closed[$dep] = true;
            }
        }

        return array_keys($closed);
    }

    public static function presetExists(string $preset): bool
    {
        return isset(self::PRESETS[$preset]);
    }
}
