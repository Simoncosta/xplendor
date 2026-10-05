<?php

/*
 * Orçamentos de serviços da XPLENDOR: valores por omissão (editáveis em cada
 * orçamento) e textos fixos das condições.
 */

return [
    // Empresa da equipa XPLENDOR (dona dos Clientes da XPLENDOR). Sem valor, usa a empresa do utilizador root.
    'team_company_id' => env('XPLENDOR_COMPANY_ID'),

    'validity_days' => 30,

    'defaults' => [
        'minimum_contract_months' => 3,
        'payment_terms' => 'Serviços mensais: pagamento antecipado, por transferência bancária, até ao dia 8 de cada mês. Valor único: 50% na adjudicação e 50% na entrega.',
        'global_discount_label' => 'Desconto de pacote',
    ],

    'texts' => [
        'vat_note' => 'Acresce IVA à taxa legal em vigor.',
        'vat_condition' => 'Todos os preços são apresentados sem IVA. Acresce IVA à taxa legal em vigor.',
        'ads_condition' => 'O orçamento de anúncios é pago diretamente pelo cliente às plataformas e não está incluído neste orçamento.',
    ],
];
