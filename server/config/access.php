<?php

/*
 * ACL (documents/ACL-DESENHO.md).
 *  · mode = shadow: o middleware permission calcula a decisão de cada rota de empresa e
 *    regista as divergências face à resposta de hoje, SEM bloquear (F1 e F2).
 *  · mode = enforce: o middleware recusa com 403 e o motivo (F3).
 */
return [
    'mode' => env('ACCESS_MODE', 'shadow'),
];
