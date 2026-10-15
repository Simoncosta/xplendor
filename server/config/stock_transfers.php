<?php

/*
 * XPLENDOR — Fatia 7c: transferências do Economato para os restaurantes.
 */
return [
    // PASSO 0 da Fatia 7c: Guia (saída no Economato) e Entrada (no restaurante) feitas pela XPLENDOR, com o
    // armazém, a quantidade e o preço certos. Enquanto for false, o envio para o PingWin fica bloqueado
    // (o pedido, a preparação e o circuito funcionam). Só se liga depois de provado com os artigos de teste.
    'send_proven' => (bool) env('STOCK_TRANSFER_SEND_PROVEN', false),

    // O envio sem encomenda (P3: Guia nova) não foi exercido no PingWin no passo 0 (a conversão funcionou
    // também na Baixa). Fica bloqueado até se provar com os artigos de teste.
    'new_guide_proven' => (bool) env('STOCK_TRANSFER_NEW_GUIDE_PROVEN', false),

    // O preço do envio afasta-se do último preço de compra mais do que isto (%): pede confirmação explícita.
    'price_gap_pct' => (float) env('STOCK_TRANSFER_PRICE_GAP_PCT', 30),

    // Encomendas lidas do PingWin: os últimos N dias.
    'orders_days' => 60,

    // Encomendas dos restaurantes (as que se transformam em Guia) e requisições antigas (só consulta).
    'order_docconfigs' => ['584955579139630867' => 'Encomenda Bebidas', '62590524561076436' => 'Encomenda Produção'],
    'legacy_docconfigs' => ['1207' => 'Requisição interna', '585727986578670852' => 'Requisição interna', '585727986578670856' => 'Requisição interna'],

    // Documentos de saída que o PingWin aceita a partir de uma encomenda.
    'exit_docconfigs' => ['1102' => 'Guia de ativos próprios', '1007' => 'Guia de transporte'],
    'entry_docconfig' => '1104',

    // Circuitos iniciais por empresa (criados na primeira visita; editáveis no ecrã Circuito).
    'default_routes' => [
        5 => [
            ['name' => 'Economato → Tabern Yuko Baixa', 'origin_warehouse_id' => '584955579139620864', 'origin_store_id' => '1099511639284',
                'dest_warehouse_id' => '1649601156541', 'dest_store_id' => '1099845342604'],
            ['name' => 'Economato → Tabern Yuko Original', 'origin_warehouse_id' => '584955579139620864', 'origin_store_id' => '1099511639284',
                'dest_warehouse_id' => '584955579139649883', 'dest_store_id' => '584955579139649880'],
        ],
    ],
];
