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
        App\Audit\Rules\Restaurant\UnidadeFatorRule::class,
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
        // Transferências (Fatia 7c): lê os documentos de transferência lidos do PingWin, os circuitos e o stock.
        App\Audit\Rules\Restaurant\Transfers\GuiaOrigemErradaRule::class,
        App\Audit\Rules\Restaurant\Transfers\EntradaCustoRule::class,
        App\Audit\Rules\Restaurant\Transfers\EnvioSemRececaoRule::class,
        App\Audit\Rules\Restaurant\Transfers\RececaoSemEnvioRule::class,
        App\Audit\Rules\Restaurant\Transfers\RecebidoDiferenteRule::class,
        App\Audit\Rules\Restaurant\Transfers\FatorEmbalagemRule::class,
        App\Audit\Rules\Restaurant\Transfers\PcmEntreArmazensRule::class,
        App\Audit\Rules\Restaurant\Transfers\EncomendaPorEnviarRule::class,
        // Automóvel
        App\Audit\Rules\Automotive\AutocaravanaSemTipologiaRule::class,
        // Tracking dos sites (GA4 × XPLENDOR)
        App\Audit\Rules\Tracking\Ga4DivergenciaRule::class,
        App\Audit\Rules\Tracking\PedidosRecusadosRule::class,
    ],

    // Transferências (Fatia 7c): severidade pelo valor em jogo (euros) e os limites das regras T2, T3, T7 e T8.
    'transfers_severity_eur' => [
        'alta' => (float) env('AUDIT_TRANSFERS_HIGH_EUR', 100),
        'media' => (float) env('AUDIT_TRANSFERS_MEDIUM_EUR', 20),
    ],
    'transfers_cost_gap_pct' => 5,      // T2: custo da Entrada × PCM do Economato
    'transfers_reception_days' => 1,    // T3: dias até uma Guia sem Entrada ser achado
    'transfers_pcm_gap_pct' => 30,      // T7: PCM entre armazéns
    'transfers_order_days' => 3,        // T8: dias até uma encomenda por enviar ser achado

    // Tracking: quantos pedidos recusados por dia (de um tipo, estado e motivo) fazem um achado.
    'tracking_rejections_per_day' => (int) env('AUDIT_TRACKING_REJECTIONS_PER_DAY', 20),
];
