<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * XPLENDOR — Configurações: o catálogo das definições que saíram do .env. Cada definição diz o nível
 * (empresa, empresa só root, plataforma), o tipo, o valor por defeito, a chave de config (que ainda
 * lê a variável antiga do .env, só por compatibilidade), a secção do ecrã e o texto do InfoTip.
 *
 * Prioridade: o valor na base de dados > a variável do .env (via config) > o valor por defeito.
 */
final class SettingsCatalog
{
    /** Definições da empresa: o administrador da empresa muda-as em Configurações › Restauração e Auditoria. */
    public const COMPANY = 'empresa';
    /** Definições da empresa que só o root muda (Admin › Integrações › PingWin). */
    public const COMPANY_ROOT = 'empresa_root';
    /** Definições da plataforma (todas as empresas), só root. */
    public const PLATFORM = 'plataforma';

    public const SECTIONS = [
        'menu' => 'Engenharia de menu',
        'stock' => 'Stock, contagens e transferências',
        'auditoria' => 'Auditoria',
        'caixa' => 'Documentos de caixa',
        'calendario' => 'Calendário',
    ];

    public const VOIDS_CONSENT = 'Ao ligar, o dono autoriza mostrar padrões por funcionário ao administrador.';

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $sev = fn (string $area, string $configKey, string $env, string $level, float $def, string $label) => [
            'level' => self::COMPANY, 'section' => 'auditoria', 'type' => 'eur', 'default' => $def, 'min' => 0, 'max' => 100000,
            'config' => "auditoria.{$configKey}.{$level}", 'env' => $env, 'label' => $label,
            'info' => $level === 'alta'
                ? "Um achado de {$area} com pelo menos este valor em jogo fica com urgência alta."
                : "Um achado de {$area} com pelo menos este valor em jogo (e abaixo do limite de urgência alta) fica com urgência média; abaixo, baixa.",
        ];

        return [
            // ── Empresa ──────────────────────────────────────────────────────────────────────
            'menu.food_cost_target_pct' => [
                'level' => self::COMPANY, 'section' => 'menu', 'type' => 'pct', 'default' => 35.0, 'min' => 1, 'max' => 100,
                'config' => 'menu_engineering.food_cost_target_pct', 'env' => 'MENU_FOOD_COST_TARGET_PCT',
                'label' => 'Food cost alvo (geral)',
                'info' => 'A percentagem do preço sem IVA que os ingredientes podem custar. Um prato acima deste valor aparece na Auditoria.',
            ],
            'menu.family_targets' => [
                'level' => self::COMPANY, 'section' => 'menu', 'type' => 'family_pct', 'default' => [], 'min' => 1, 'max' => 100,
                'config' => 'menu_engineering.family_targets', 'env' => null,
                'label' => 'Food cost alvo por família',
                'info' => 'Um alvo diferente para uma família de vendas (por exemplo, 30 % nas bebidas). As famílias sem alvo próprio usam o geral.',
            ],
            'stock.recount_eur' => [
                'level' => self::COMPANY, 'section' => 'stock', 'type' => 'eur', 'default' => 25.0, 'min' => 0, 'max' => 100000,
                'config' => 'stock_counts.recount_eur', 'env' => 'STOCK_COUNT_RECOUNT_EUR',
                'label' => 'Recontar a partir de (€)',
                'info' => 'Na revisão de uma contagem, pede-se para recontar um artigo quando a diferença vale pelo menos este valor.',
            ],
            'stock.recount_pct' => [
                'level' => self::COMPANY, 'section' => 'stock', 'type' => 'pct', 'default' => 20.0, 'min' => 0, 'max' => 1000,
                'config' => 'stock_counts.recount_pct', 'env' => 'STOCK_COUNT_RECOUNT_PCT',
                'label' => 'Recontar a partir de (%)',
                'info' => 'Também se pede para recontar quando a diferença passa esta percentagem do stock esperado (e a quantidade mínima abaixo).',
            ],
            'stock.recount_min_qty' => [
                'level' => self::COMPANY, 'section' => 'stock', 'type' => 'number', 'default' => 1.0, 'min' => 0, 'max' => 100000,
                'config' => 'stock_counts.recount_min_qty', 'env' => 'STOCK_COUNT_RECOUNT_MIN_QTY',
                'label' => 'Quantidade mínima para recontar',
                'info' => 'Uma diferença em percentagem só pede recontagem se for de pelo menos esta quantidade (na unidade base do artigo).',
            ],
            'stock.transfer_price_gap_pct' => [
                'level' => self::COMPANY, 'section' => 'stock', 'type' => 'pct', 'default' => 30.0, 'min' => 0, 'max' => 1000,
                'config' => 'stock_transfers.price_gap_pct', 'env' => 'STOCK_TRANSFER_PRICE_GAP_PCT',
                'label' => 'Diferença de preço que pede confirmação',
                'info' => 'Numa transferência, quando o preço se afasta do último preço de compra mais do que esta percentagem, pede-se uma confirmação antes de enviar.',
            ],
            'stock.document_store_id' => [
                'level' => self::COMPANY, 'section' => 'stock', 'type' => 'store', 'default' => '', 'config' => 'stock_counts.document_store_id', 'env' => 'STOCK_DOC_STORE_ID',
                'label' => 'Loja onde se emitem os documentos de stock',
                'info' => 'A loja do PingWin em que se fazem as contagens, o desperdício e as transferências. Vazio: a loja que todos os armazéns servem (o Economato).',
            ],
            'audit.transfers_severity_high' => $sev('transferências', 'transfers_severity_eur', 'AUDIT_TRANSFERS_HIGH_EUR', 'alta', 100.0, 'Transferências: urgência alta a partir de'),
            'audit.transfers_severity_medium' => $sev('transferências', 'transfers_severity_eur', 'AUDIT_TRANSFERS_MEDIUM_EUR', 'media', 20.0, 'Transferências: urgência média a partir de'),
            'audit.units_severity_high' => $sev('unidades', 'units_severity_eur', 'AUDIT_UNITS_HIGH_EUR', 'alta', 100.0, 'Unidades: urgência alta a partir de'),
            'audit.units_severity_medium' => $sev('unidades', 'units_severity_eur', 'AUDIT_UNITS_MEDIUM_EUR', 'media', 20.0, 'Unidades: urgência média a partir de'),
            'audit.money_severity_high' => $sev('dinheiro', 'money.severity_eur', 'AUDIT_MONEY_HIGH_EUR', 'alta', 100.0, 'Dinheiro: urgência alta a partir de'),
            'audit.money_severity_medium' => $sev('dinheiro', 'money.severity_eur', 'AUDIT_MONEY_MEDIUM_EUR', 'media', 20.0, 'Dinheiro: urgência média a partir de'),
            'audit.voids_by_employee' => [
                'level' => self::COMPANY, 'section' => 'auditoria', 'type' => 'bool', 'default' => false, 'config' => null, 'env' => null, 'owner' => true,
                'label' => 'Anulações por funcionário',
                'info' => 'Compara as anulações de cada funcionário com as dos colegas da mesma loja e semana. Só o administrador vê os achados, com os nomes. '
                    . self::VOIDS_CONSENT,
                'consent' => self::VOIDS_CONSENT,
            ],
            'pingwin.pos_documents_sync' => [
                'level' => self::COMPANY, 'section' => 'caixa', 'type' => 'bool', 'default' => true,
                'config' => 'services.pingwin.pos_documents_sync', 'env' => 'PINGWIN_POS_DOCUMENTS_SYNC',
                'label' => 'Ler os documentos de caixa',
                'info' => 'Todas as noites, fora do horário de serviço, a XPLENDOR lê no PingWin as anulações, as consultas de mesa e as vendas do dia anterior. Só leitura.',
            ],
            'calendar.municipal_holiday' => [
                'level' => self::COMPANY, 'section' => 'calendario', 'type' => 'month_day', 'default' => '', 'config' => null, 'env' => null,
                'label' => 'Feriado municipal',
                'info' => 'O dia do feriado do concelho (por exemplo, 24/06 no Porto). Aparece no Calendário e a previsão avisa que é um dia atípico.',
            ],

            // ── Empresa, só root (a integração PingWin da empresa) ────────────────────────────
            'pingwin.writes_enabled' => [
                'level' => self::COMPANY_ROOT, 'type' => 'bool', 'default' => true, 'config' => null, 'env' => null,
                'label' => 'Escrita no PingWin',
                'info' => 'Desligada, a XPLENDOR não grava nada no PingWin desta empresa (fichas, unidades, stock, documentos, artigos, fornecedores).',
            ],
            'pingwin.test_mode' => [
                'level' => self::COMPANY_ROOT, 'type' => 'bool', 'default' => false, 'config' => null, 'env' => null,
                'label' => 'Modo de testes',
                'info' => 'Ligado, só se grava em artigos cuja descrição começa por "TESTE XPLENDOR" (e em armazéns com esse nome). Substitui as listas de artigos autorizados do .env.',
            ],

            // ── Plataforma (todas as empresas), só root: as capacidades provadas no PingWin ──────────
            'pingwin.proven.count' => [
                'level' => self::PLATFORM, 'type' => 'bool', 'default' => false, 'config' => 'stock_counts.unlisted_untouched_proven', 'env' => 'STOCK_COUNT_UNLISTED_UNTOUCHED_PROVEN',
                'label' => 'Contagem provada',
                'info' => 'Provado que uma Contagem Fechada só altera os artigos que lista. Sem a prova, o envio das contagens fica bloqueado.',
            ],
            'pingwin.proven.waste' => [
                'level' => self::PLATFORM, 'type' => 'bool', 'default' => false, 'config' => 'stock_counts.waste_send_proven', 'env' => 'STOCK_WASTE_SEND_PROVEN',
                'label' => 'Desperdício provado',
                'info' => 'Provado que o documento de desperdício fica no armazém escolhido. Sem a prova, o envio do desperdício fica bloqueado.',
            ],
            'pingwin.proven.transfers' => [
                'level' => self::PLATFORM, 'type' => 'bool', 'default' => false, 'config' => 'stock_transfers.send_proven', 'env' => 'STOCK_TRANSFER_SEND_PROVEN',
                'label' => 'Transferências provadas',
                'info' => 'Provado que a Guia e a Entrada feitas pela XPLENDOR ficam com o armazém, a quantidade e o preço certos.',
            ],
            'pingwin.proven.new_guide' => [
                'level' => self::PLATFORM, 'type' => 'bool', 'default' => false, 'config' => 'stock_transfers.new_guide_proven', 'env' => 'STOCK_TRANSFER_NEW_GUIDE_PROVEN',
                'label' => 'Guia sem encomenda provada',
                'info' => 'Provado o envio sem encomenda (uma Guia nova). Sem a prova, só se envia a partir de uma encomenda.',
            ],
            'pingwin.proven.units' => [
                'level' => self::PLATFORM, 'type' => 'bool', 'default' => false, 'config' => 'auditoria.unit_write_proven', 'env' => 'UNIT_WRITE_PROVEN',
                'label' => 'Unidades provadas',
                'info' => 'Provada a escrita das unidades de um artigo. Sem a prova, "Aplicar no PingWin" do Guião de unidades fica "Disponível em breve".',
            ],
        ];
    }

    public static function get(string $key): array
    {
        $d = self::all()[$key] ?? null;
        if (! $d) {
            throw new \InvalidArgumentException("Definição desconhecida: {$key}");
        }

        return $d + ['key' => $key];
    }

    /** @return list<string> */
    public static function keys(string $level): array
    {
        return array_keys(array_filter(self::all(), fn ($d) => $d['level'] === $level));
    }
}
