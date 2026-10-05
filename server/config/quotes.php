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
        // Só aparecem no ecrã e no PDF quando o orçamento tem linhas MENSAIS:
        'minimum_contract_months' => 3,
        'monthly_start_terms' => 'Os serviços mensais têm início no mês seguinte à aceitação.',
        'payment_terms_monthly' => 'Pagamento antecipado, por transferência bancária, até ao dia 8 de cada mês.',
        // Só aparece quando o orçamento tem linhas de VALOR ÚNICO:
        'payment_terms_one_off' => '50% na adjudicação e 50% na entrega.',
        'global_discount_label' => 'Desconto de pacote',
    ],

    'texts' => [
        'vat_note' => 'Acresce IVA à taxa legal em vigor.',
        'vat_condition' => 'Todos os preços são apresentados sem IVA. Acresce IVA à taxa legal em vigor.',
        'ads_condition' => 'O orçamento de anúncios é pago diretamente pelo cliente às plataformas e não está incluído neste orçamento.',
    ],
];
