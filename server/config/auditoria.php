<?php

/*
 * XPLENDOR — Auditoria (Fatia 2): o registo central das regras. O motor (app/Audit) não conhece
 * nenhuma regra em concreto; cada ramo acrescenta aqui as suas classes.
 * (Não confundir com config/audit.php, do owen-it/laravel-auditing.)
 */
return [
    'rules' => [
        // Restauração (lê o espelho do PingWin; nunca chama o PingWin)
        App\Audit\Rules\Restaurant\IvaVendaRule::class,
        App\Audit\Rules\Restaurant\PrecoCompraRule::class,
        App\Audit\Rules\Restaurant\UnidadeTrocadaRule::class,
        // U9 (Auditoria v2.1): substitui a Regra 3 (UnidadeFatorRule), com o mesmo código.
        App\Audit\Rules\Restaurant\Units\UnidadeGlobalIncoerenteRule::class,
        App\Audit\Rules\Restaurant\UnidadeSemConversaoRule::class,
        App\Audit\Rules\Restaurant\DuplicadosRule::class,
        App\Audit\Rules\Restaurant\FamiliaSuspeitaRule::class,
        App\Audit\Rules\Restaurant\VendidoSemFichaRule::class,
        App\Audit\Rules\Restaurant\Fichas\LojaSemFichaRule::class,
        App\Audit\Rules\Restaurant\Fichas\VersaoVaziaRule::class,
        App\Audit\Rules\Restaurant\Fichas\VersoesMesmaDataRule::class,
        App\Audit\Rules\Restaurant\Fichas\ComponenteInativoRule::class,
        App\Audit\Rules\Restaurant\Fichas\ComponenteSemCustoRule::class,
        App\Audit\Rules\Restaurant\Fichas\ParecidoCompradoRule::class,
        App\Audit\Rules\Restaurant\Fichas\PorConfirmarRule::class,
        App\Audit\Rules\Restaurant\Fichas\DiferencaPingwinRule::class,
        App\Audit\Rules\Restaurant\DocumentoDataSerieRule::class,
        App\Audit\Rules\Restaurant\FaturaSemLinhasRule::class,
        // Stock (Fatia 7a): lê o espelho do stock e o consumo teórico.
        App\Audit\Rules\Restaurant\Stock\StockNegativoRule::class,
        App\Audit\Rules\Restaurant\Stock\CompradoNuncaConsumidoRule::class,
        App\Audit\Rules\Restaurant\Stock\CoberturaImpossivelRule::class,
        App\Audit\Rules\Restaurant\Stock\ArmazemSemEntradasRule::class,
        // Engenharia de menu (Fatia 3): lê o snapshot da engenharia de menu.
        App\Audit\Rules\Restaurant\Menu\FoodCostAcimaAlvoRule::class,
        App\Audit\Rules\Restaurant\Menu\FichaSemComprasRule::class,
        // Transferências (Fatia 7c): lê os documentos de transferência lidos do PingWin, os circuitos e o stock.
        App\Audit\Rules\Restaurant\Transfers\GuiaOrigemErradaRule::class,
        App\Audit\Rules\Restaurant\Transfers\EntradaCustoRule::class,
        App\Audit\Rules\Restaurant\Transfers\EnvioSemRececaoRule::class,
        App\Audit\Rules\Restaurant\Transfers\RececaoSemEnvioRule::class,
        App\Audit\Rules\Restaurant\Transfers\RecebidoDiferenteRule::class,
        App\Audit\Rules\Restaurant\Transfers\FatorEmbalagemRule::class,
        App\Audit\Rules\Restaurant\Transfers\PcmEntreArmazensRule::class,
        App\Audit\Rules\Restaurant\Transfers\EncomendaPorEnviarRule::class,
        // Unidades (Auditoria v2, U0 a U8): lê as evidências das unidades de cada artigo (UnitEvidence).
        App\Audit\Rules\Restaurant\Units\CombinacaoNovaRule::class,
        App\Audit\Rules\Restaurant\Units\EmbalagemFatorUmRule::class,
        App\Audit\Rules\Restaurant\Units\BaseGrandeRule::class,
        App\Audit\Rules\Restaurant\Units\PesoVariavelRule::class,
        App\Audit\Rules\Restaurant\Units\FornecedoresUnidadesRule::class,
        App\Audit\Rules\Restaurant\Units\PrecoFamiliaRule::class,
        App\Audit\Rules\Restaurant\Units\UnidadesSemUsoRule::class,
        App\Audit\Rules\Restaurant\Units\BebidaFichaUnidadeRule::class,
        App\Audit\Rules\Restaurant\Units\PorIdentificarRule::class,
        // Dinheiro (Auditoria v2-C, C1 a C6): onde está a fugir dinheiro (compras, margens, caixa).
        App\Audit\Rules\Restaurant\Money\PrecoSubiuRule::class,
        App\Audit\Rules\Restaurant\Money\MaisBaratoRule::class,
        App\Audit\Rules\Restaurant\Money\MargemPratoRule::class,
        App\Audit\Rules\Restaurant\Money\ForaDoPadraoRule::class,
        App\Audit\Rules\Restaurant\Money\FaturaDuplicadaRule::class,
        App\Audit\Rules\Restaurant\Money\GuiaSemFaturaRule::class,
        // Fatia 5: o inverso da C6 (a fatura refere guias que não estão lançadas).
        App\Audit\Rules\Restaurant\Money\FaturaGuiasEmFaltaRule::class,
        // Automóvel
        App\Audit\Rules\Automotive\AutocaravanaSemTipologiaRule::class,
        // Tracking dos sites (GA4 × XPLENDOR)
        App\Audit\Rules\Tracking\Ga4DivergenciaRule::class,
        App\Audit\Rules\Tracking\PedidosRecusadosRule::class,
    ],

    // A corrida: cada regra corre num job próprio com este limite de tempo (segundos) e de memória.
    // Uma regra que passe o limite fica "Demorou demasiado" e a corrida continua. Uma corrida sem
    // progresso há mais de stale_minutes passa a "falhou" sozinha, com o passo onde parou.
    'rule_timeout' => (int) env('AUDIT_RULE_TIMEOUT', 120),
    'rule_memory' => env('AUDIT_RULE_MEMORY', '512M'),
    'stale_minutes' => (int) env('AUDIT_STALE_MINUTES', 30),

    // Transferências (Fatia 7c): severidade pelo valor em jogo (euros) e os limites das regras T2, T3, T7 e T8.
    'transfers_severity_eur' => [
        'alta' => (float) env('AUDIT_TRANSFERS_HIGH_EUR', 100),
        'media' => (float) env('AUDIT_TRANSFERS_MEDIUM_EUR', 20),
    ],
    'transfers_cost_gap_pct' => 5,      // T2: custo da Entrada × PCM do Economato
    'transfers_reception_days' => 1,    // T3: dias até uma Guia sem Entrada ser achado
    'transfers_pcm_gap_pct' => 30,      // T7: PCM entre armazéns
    'transfers_order_days' => 3,        // T8: dias até uma encomenda por enviar ser achado

    // Auditoria v2 (Unidades): a escrita das unidades de um artigo no PingWin só se liga depois de
    // provada (PASSO 0 no TESTE A). Sem prova, "Aplicar no PingWin" fica "Disponível em breve".
    'unit_write_proven' => (bool) env('UNIT_WRITE_PROVEN', false),
    // Severidade das regras U pelo valor em jogo (euros), como nas transferências.
    'units_severity_eur' => [
        'alta' => (float) env('AUDIT_UNITS_HIGH_EUR', 100),
        'media' => (float) env('AUDIT_UNITS_MEDIUM_EUR', 20),
    ],

    // Dinheiro (Auditoria v2-C): os limiares das regras C1 a C6 e a severidade pelo € em jogo.
    'money' => [
        'severity_eur' => [
            'alta' => (float) env('AUDIT_MONEY_HIGH_EUR', 100),
            'media' => (float) env('AUDIT_MONEY_MEDIUM_EUR', 20),
        ],
        // C1: subida do preço (%) contra a mediana de 90 dias; por família (o nome da família no caminho).
        'price_rise_pct' => 8,
        'price_rise_pct_by_family' => [],
        'price_rise_max_pct' => 200,
        // C1: "mais barato noutro fornecedor" só a partir desta diferença (%) e deste € por mês.
        'cheaper_min_pct' => 3,
        'cheaper_min_eur' => 5,
        'cheaper_max_ratio' => 3,
        // C2: o food cost sobe mais do que estes pontos, ou o custo mais do que esta %.
        'margin_food_cost_pp' => 3,
        'margin_cost_pct' => 10,
        'margin_max_food_cost' => 100,
        // C3: mínimos (documentos da semana do funcionário e vezes a mediana dos colegas).
        'pattern_min_docs' => 10,
        'pattern_times_median' => 2,
        // C5: janela (dias) e total mínimo (€) das faturas em duplicado.
        'duplicate_days' => 15,
        'duplicate_min_eur' => 1,
        // C6: as guias de fornecedor e a idade (dias) a partir da qual sem fatura é achado.
        'guide_docconfigs' => ['1203', '1201'],
        'guide_days' => 30,
    ],

    // Tracking: quantos pedidos recusados por dia (de um tipo, estado e motivo) fazem um achado.
    'tracking_rejections_per_day' => (int) env('AUDIT_TRACKING_REJECTIONS_PER_DAY', 20),
];
