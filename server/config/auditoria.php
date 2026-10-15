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
        // Automóvel
        App\Audit\Rules\Automotive\AutocaravanaSemTipologiaRule::class,
        // Tracking dos sites (GA4 × XPLENDOR)
        App\Audit\Rules\Tracking\Ga4DivergenciaRule::class,
        App\Audit\Rules\Tracking\PedidosRecusadosRule::class,
    ],

    // Tracking: quantos pedidos recusados por dia (de um tipo, estado e motivo) fazem um achado.
    'tracking_rejections_per_day' => (int) env('AUDIT_TRACKING_REJECTIONS_PER_DAY', 20),
];
