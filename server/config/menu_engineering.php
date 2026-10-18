<?php

/*
 * XPLENDOR — Fatia 3: engenharia de menu, cobertura de custos e food cost (só leitura).
 */
return [
    // Período por omissão (dias até ontem).
    'period_days' => 90,

    // Exclusões automáticas (cada uma com interruptor no ecrã).
    'min_qty' => 5,                 // menos de max(5 unidades, 1 % da quantidade da família) no período
    'min_family_share_pct' => 1.0,
    'retired_days' => 30,           // sem vendas nos últimos 30 dias do período
    'uber_family' => 'Uber Eats',   // família fora da matriz principal por omissão

    // Kasavana e Smith: muito vendido se a quota na família ≥ 70 % × (1 ÷ N).
    'popularity_factor' => 0.70,

    // Classe frágil: menos de 4 artigos com custo, preços quase iguais (CV < 5 %), cobertura < 60 %.
    'fragile_min_costed' => 4,
    'fragile_price_cv_pct' => 5.0,
    'fragile_coverage_pct' => 60.0,

    // Cobertura que deixa comparar o food cost real com o teórico.
    'compare_min_coverage_pct' => 80.0,

    // Food cost alvo por omissão (regra M1) e por família de vendas (por exemplo "Bebidas › Cerveja" => 30).
    'food_cost_target_pct' => (float) env('MENU_FOOD_COST_TARGET_PCT', 35),
    'family_targets' => [],

    // Food cost por compras: documentos (a nota de crédito subtrai) e famílias de compras (com as subfamílias).
    'purchase_docconfigs' => ['1209' => 1, '584955579139752236' => 1, '1205' => -1],
    'purchase_families' => [
        'comida' => ['COMPRAS › COMIDAS'],
        'bebida' => ['COMPRAS › BEBIDAS'],
    ],
    // Subfamílias que nunca contam (por exemplo vasilhame), mesmo dentro das de cima.
    'purchase_exclude' => ['FORNECIMENTOS E SERV EXTERNOS', 'RECURSOS HUMANOS', 'OUTROS DEVEDORES/CREDORES', 'Cauções', 'vasilhame'],

    // As jogadas por classe (texto fixo; editável depois).
    'plays' => [
        'estrela' => 'Manter a receita e a porção, dar destaque no menu e não baixar o preço.',
        'cavalo' => 'É muito pedido mas deixa pouca margem: rever a porção, os ingredientes ou o fornecedor, ou subir o preço com cuidado.',
        'quebra_cabecas' => 'Deixa boa margem mas vende pouco: dar visibilidade no menu e na sugestão da sala, ou ajustar o preço.',
        'cao' => 'Vende pouco e deixa pouca margem: rever a receita e o preço, ou retirar do menu.',
    ],
];
