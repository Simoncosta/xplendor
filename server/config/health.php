<?php

/*
 * Monitorização (rota de saúde e health:watch). O serviço externo abre /api/health de 5 em 5
 * minutos (200 ou 503, sem detalhes); o health:watch avisa o root por email quando uma falha
 * dura mais de alert_after_minutes, e outra vez quando volta.
 */
return [
    // Sinais de vida: sem batimento há mais destes minutos, o scheduler ou o worker contam como parados.
    'scheduler_max_minutes' => (int) env('HEALTH_SCHEDULER_MAX_MINUTES', 5),
    'worker_max_minutes' => (int) env('HEALTH_WORKER_MAX_MINUTES', 10),

    // Disco onde vive o storage: acima desta percentagem é falha (o aviso de 70% dos media continua à parte).
    'disk_alert_percent' => (float) env('HEALTH_DISK_PERCENT', 90),

    // Uma falha só é avisada se durar pelo menos estes minutos (evita avisos por um soluço).
    'alert_after_minutes' => (int) env('HEALTH_ALERT_AFTER_MINUTES', 10),

    // Destinatários fixos do aviso (separados por vírgulas), além dos roots. Servem quando a base de
    // dados está em baixo e não há como ler os roots.
    'alert_emails' => env('HEALTH_ALERT_EMAILS', ''),
];
