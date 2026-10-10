<?php

/*
 * ACL (documents/ACL-DESENHO.md).
 *  · mode = shadow: o middleware permission calcula a decisão de cada rota de empresa e
 *    regista as divergências face à resposta de hoje, SEM bloquear (F1 e F2).
 *  · mode = enforce: o middleware recusa com 403 e o motivo (F3, o valor por omissão).
 *    Depois da F3 as verificações antigas saíram dos controllers, por isso o modo sombra só é
 *    respeitado nos testes; fora deles, o middleware bloqueia sempre.
 */
return [
    'mode' => env('ACCESS_MODE', 'enforce'),
];
