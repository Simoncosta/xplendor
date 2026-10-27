<?php

/*
 * E-mail 1 · As caixas de correio de cada pessoa (desenho de 27/10/2026).
 *
 * driver: quem envia pelas caixas.
 *  · "smtp": o cliente real (SmtpMailboxClient). Fora de produção, só envia para o servidor da
 *    caixa com real_send ligado; sem isso, a mensagem vai para o Mailpit (dev_smtp), com o mesmo
 *    remetente, para se ver o resultado sem sair do computador. Por omissão.
 *  · "fake": o cliente simulado (FakeMailboxClient), que guarda as mensagens em memória. Nos testes.
 *  · "disabled": ninguém envia (o teste responde que o envio está desligado).
 */
return [
    'driver' => env('MAILBOX_DRIVER', env('APP_ENV', 'production') === 'testing' ? 'fake' : 'smtp'),

    // Fora de produção: true envia mesmo para o servidor da caixa (para um teste à mão com uma caixa de teste).
    'real_send' => (bool) env('MAILBOX_REAL_SEND', false),

    // Para onde vai o envio fora de produção sem real_send (o Mailpit do docker-compose de dev).
    'dev_smtp' => [
        'host' => env('MAILBOX_DEV_SMTP_HOST', 'mailpit'),
        'port' => (int) env('MAILBOX_DEV_SMTP_PORT', 1025),
    ],

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
