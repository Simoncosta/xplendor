<?php

/*
 * XPLENDOR — Finanças › Mês: resultado e tesouraria num só ecrã (só leitura sobre os espelhos do
 * PingWin: documentos e linhas de fornecedor, conta corrente e vendas por artigo).
 */
return [
    // Documentos que são gasto (sinal: a nota de crédito subtrai). Encomendas, guias e requisições
    // não são gasto; a nota de liquidação é um pagamento (tesouraria), não um gasto.
    'expense_docconfigs' => [
        '1209' => 1,                 // Fatura de fornecedor
        '584955579139752236' => 1,   // Fatura-recibo compra (paga no ato)
        '1205' => -1,                // Nota de crédito de fornecedor
        '1206' => 1,                 // Nota de débito de fornecedor
        '1213' => 1,                 // Despesas
        '1225' => 1,                 // Despesa operacional
        '1226' => 1,                 // Auto Fatura
    ],
    // Pagamentos com data (saídas): a nota de liquidação e a nota de liquidação global.
    'payment_docconfigs' => ['1204', '1227'],

    // Estados: fechado conta; aberto e em edição contam mas ficam destacados ("ainda não fechados no
    // PingWin; não contam no stock"); anulado nunca conta.
    'closed_status' => '8002',
    'open_statuses' => ['8001', '8004'],
    'voided_status' => '8003',

    // Grupos, pela família das linhas (caminho da família no PingWin; a primeira regra que bate).
    // Comida e Bebidas são as famílias de compras da Engenharia de menu (o food cost é o mesmo).
    // "fora" = fora do resultado: depósitos e outros movimentos que não são gasto do mês.
    'groups' => [
        'comida'  => ['label' => 'Comida'],
        'bebida'  => ['label' => 'Bebidas'],
        'pessoal' => ['label' => 'Pessoal'],
        'fse'     => ['label' => 'Fornecimentos e serviços externos'],
        'outros'  => ['label' => 'Outros'],
        'fora'    => ['label' => 'Fora do resultado'],
    ],
    'rules' => [
        // Cauções e vasilhame: são depósitos, não gastos.
        ['group' => 'fora', 'match' => 'Cauções', 'reason' => 'Caução ou vasilhame: é um depósito, não um gasto.'],
        ['group' => 'fora', 'match' => 'vasilhame', 'reason' => 'Caução ou vasilhame: é um depósito, não um gasto.'],
        // O reembolso do capital de um empréstimo e os investimentos não são gasto do mês.
        ['group' => 'fora', 'match' => 'Amortizações de capital', 'reason' => 'Reembolso do capital de um empréstimo: não é gasto (os juros são).'],
        ['group' => 'fora', 'match' => 'INVESTIMENTOS', 'reason' => 'Investimento: não é gasto do mês.'],
        ['group' => 'fora', 'match' => 'Adiantamentos', 'reason' => 'Adiantamento: não é gasto do mês.'],
        ['group' => 'comida', 'match' => 'COMPRAS › COMIDAS'],
        ['group' => 'bebida', 'match' => 'COMPRAS › BEBIDAS'],
        ['group' => 'pessoal', 'match' => 'RECURSOS HUMANOS'],
        ['group' => 'fse', 'match' => 'FORNECIMENTOS E SERV EXTERNOS'],
        // As rendas e os seguros são fornecimentos e serviços externos, mesmo noutra família do PingWin.
        ['group' => 'fse', 'match' => 'Rendas e alugueres'],
        ['group' => 'fse', 'match' => 'SEGUROS'],
    ],
    // Linhas que são depósito pela descrição do artigo (ex.: "VALOR DO DEPOSITO"), em qualquer família.
    'deposit_pattern' => '/\b(DEP[OÓ]SITO|DESPOSITO|CAU[CÇ][AÃ]O|VASILHAME)\b/iu',

    // A família habitual de um fornecedor (para os documentos e linhas sem família): a de maior valor
    // nas linhas dele nos últimos N meses.
    'habitual_months' => 12,

    // Aviso: um grupo abaixo de metade da média dos 3 meses anteriores (com média de pelo menos 1 000 €).
    'warn_below_avg_ratio' => 0.5,

    // "Fechar no PingWin" a partir da lista: no máximo 10 de cada vez; acerto até 0,05 €.
    'close_max_batch' => 10,
    'close_tolerance_cents' => 5,
    'close_docconfigs' => ['1209', '584955579139752236', '1205', '1206'],
];
