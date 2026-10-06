<?php

/*
 * Link de aprovação de conteúdos por lote (Linha Editorial, F3c).
 */

return [
    // Validade do link, em dias (prolongável pela equipa).
    'validity_days' => 14,

    // Lembrete ao cliente quando o lote está pendente há N dias desde o envio (uma vez).
    'client_reminder_days' => (int) env('CONTENT_REVIEW_CLIENT_REMINDER_DAYS', 2),

    // Aviso à equipa quando o lote está pendente há N dias desde o envio (uma vez).
    'team_reminder_days' => 4,

    // Aviso urgente quando faltam menos de N horas para a data da publicação sem aprovação.
    'urgent_hours' => 48,

    // Avisos de abertura: no máximo um a cada N horas por link.
    'open_alert_every_hours' => 6,

    // Aberturas apagadas ao fim de N meses (fica o contador do link).
    'opens_retention_months' => 12,

    // Ficheiros no link: URLs assinados de curta duração (minutos), nunca além da validade do link.
    'media_url_minutes' => 30,

    // Resumo por email à equipa XPLENDOR (separados por vírgulas). Sem valor, os emails da equipa (root).
    'team_emails' => env('CONTENT_REVIEW_TEAM_EMAILS'),
];
