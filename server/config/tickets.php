<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Alteração ao site (ticket pago "site_change")
    |--------------------------------------------------------------------------
    | Taxa horária cobrada por alterações ao site do stand. O valor do orçamento
    | é horas × esta taxa. Guardado AQUI (um sítio só) — mudar a taxa é mexer
    | apenas nesta linha; nunca hardcoded espalhado pelo código.
    |
    | O IVA NÃO é calculado pelo software: o pagamento e a fatura acontecem fora
    | (o Simon anexa o PDF da fatura ao ticket). Mostramos o valor-base e uma
    | nota "acresce IVA à taxa legal" — sem inventar um total que confunda.
    */
    'site_change_hourly_rate' => (float) env('SITE_CHANGE_HOURLY_RATE', 25),
];
