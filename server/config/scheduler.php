<?php

/*
 * Tarefas agendadas que chamam serviços externos (Meta, PingWin, CoverManager, Carmine,
 * Standvirtual, IA, os feeds públicos do Radar). Em produção correm sempre (nada muda); fora de
 * produção ficam desligadas por omissão, para o dev nunca fazer chamadas reais sozinho.
 * SCHEDULE_EXTERNAL_JOBS=true liga-as (por exemplo, para testar uma sincronização no dev de propósito).
 */
return [
    'external_jobs' => (bool) env('SCHEDULE_EXTERNAL_JOBS', env('APP_ENV', 'production') === 'production'),

    // Os nomes das tarefas (routes/console.php) que dependem do interruptor acima.
    'external_job_names' => [
        'fetch-meta-ads-metrics', 'dispatch-meta-account-insights-sync', 'dispatch-meta-ad-insights-sync',
        'refresh-stale-market-aggregates', 'sync-carmine-cars', 'restaurant-daily-sync',
        'pingwin-supplier-cc-nightly', 'pingwin-supplier-documents-nightly', 'pingwin-supplier-document-lines-nightly',
        'ocr-pingwin-relink-nightly', 'social-followers-daily', 'radar-collect-feeds',
        // P2 do fecho: tarefas internas cujo trabalho chama a IA (as frases da Bússola do stand).
        // Contam como externas: no dev ficam desligadas, como as outras. A restauração já chegava
        // à IA pela restaurant-daily-sync, que já era externa.
        'bussola-stand-nightly', 'bussola-imoveis-nightly',
        // Bússola Master (R3): a referência do INE do imobiliário (lê o INE).
        'ine-housing-prices-monthly',
    ],

    // As tarefas agendadas que chegam à IA (pelos trabalhos que põem na fila). Todas têm de estar
    // em external_job_names; o teste ScheduledExternalJobsTest confirma-o.
    'ai_job_names' => ['restaurant-daily-sync', 'bussola-stand-nightly', 'bussola-imoveis-nightly', 'radar-collect-feeds'],
];
