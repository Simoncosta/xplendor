<?php

/*
 * XPLENDOR — Fatia 7b: contagens de stock e desperdício.
 */
return [
    // Revisão: "Recontar?" quando |€| ≥ recount_eur, ou |%| ≥ recount_pct e |diferença| ≥ recount_min_qty.
    'recount_eur' => (float) env('STOCK_COUNT_RECOUNT_EUR', 25),
    'recount_pct' => (float) env('STOCK_COUNT_RECOUNT_PCT', 20),
    'recount_min_qty' => (float) env('STOCK_COUNT_RECOUNT_MIN_QTY', 1),

    // ABC por armazém (valor acumulado): A até 80%, B até 95%, C o resto.
    'abc_a' => 80,
    'abc_b' => 95,

    // Artigos a contar: com stock ou com movimento no armazém nos últimos N dias.
    'movement_days' => 90,

    // PASSO 0.3 da Fatia 7b: uma Contagem Fechada só muda os artigos que lista? Ainda não foi provado
    // no PingWin (os artigos de teste não existiam). Enquanto for false, o envio para o PingWin fica
    // bloqueado (a contagem e a revisão funcionam). Só se liga depois de provado num armazém de teste.
    'unlisted_untouched_proven' => (bool) env('STOCK_COUNT_UNLISTED_UNTOUCHED_PROVEN', false),

    // PASSO 0.5 da Fatia 7b: o Desperdício no armazém certo (pela entidade do cabeçalho) ainda não foi provado no
    // PingWin. Enquanto for false, o envio do desperdício fica bloqueado (o registo funciona).
    'waste_send_proven' => (bool) env('STOCK_WASTE_SEND_PROVEN', false),

    // A loja em que se fazem os documentos de stock (vazio = a que todos os armazéns servem: o Economato).
    'document_store_id' => env('STOCK_DOC_STORE_ID', ''),
];
