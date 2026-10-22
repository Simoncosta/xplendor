<?php

/*
 * F2 · Publicação automática no Instagram e no Facebook (desenho de 22/10/2026).
 *
 * driver: quem publica.
 *  · "fake": o cliente simulado (FakeMetaPublishingClient); marca como publicado com links falsos
 *    (.invalid) e nunca chama a Meta. Por omissão fora de produção, para testar o fluxo no dev.
 *  · "meta": o cliente real (MetaGraphPublishingClient, F2.4), só com a guarda das chamadas
 *    externas ligada. Só quando o Simon o ligar em produção.
 *  · "disabled": ninguém publica (o publicador não faz nada). Por omissão em produção.
 */
return [
    'driver' => env('SOCIAL_PUBLISHING_DRIVER', env('APP_ENV', 'production') === 'production' ? 'disabled' : 'fake'),
];
