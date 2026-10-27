<?php

/*
 * E-mail 1 · As caixas de correio de cada pessoa (desenho de 27/10/2026).
 *
 * driver: quem envia pelas caixas (e lê o IMAP, E-mail 3).
 *  · "smtp": os clientes reais (SmtpMailboxClient e WebklexMailboxImap), ligados ao servidor
 *    verdadeiro da caixa, também em dev (E-mail 3b). Por omissão.
 *  · "fake": os clientes simulados, que guardam tudo em memória. Nos testes.
 *  · "disabled": ninguém envia nem lê.
 *
 * Fora de produção, as mensagens enviadas pelas caixas vão só para o endereço da própria caixa,
 * com o destinatário original no assunto (DevRecipientGuard): o dev nunca escreve a clientes.
 */
return [
    'driver' => env('MAILBOX_DRIVER', env('APP_ENV', 'production') === 'testing' ? 'fake' : 'smtp'),

    // Segundos à espera do servidor de e-mail antes de desistir.
    'timeout' => 15,

    // E-mail 2: limite por caixa (envios um a um; as mensagens em erro também contam).
    'limits' => [
        'per_hour' => (int) env('MAILBOX_LIMIT_PER_HOUR', 30),
        'per_day' => (int) env('MAILBOX_LIMIT_PER_DAY', 200),
    ],

    // As portas aceites (as dos servidores de e-mail: nada de apontar a caixa para outros serviços).
    'smtp_ports' => [465, 587, 25, 2525],
    'imap_ports' => [993, 143],
];
