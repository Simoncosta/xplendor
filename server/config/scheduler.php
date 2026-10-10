<?php

/*
 * Tarefas agendadas que chamam serviços externos (Meta, PingWin, CoverManager, Carmine,
 * Standvirtual, IA). Em produção correm sempre (nada muda); fora de produção ficam desligadas
 * por omissão, para o dev nunca fazer chamadas reais sozinho. SCHEDULE_EXTERNAL_JOBS=true
 * liga-as (por exemplo, para testar uma sincronização no dev de propósito).
 */
return [
    'external_jobs' => (bool) env('SCHEDULE_EXTERNAL_JOBS', env('APP_ENV', 'production') === 'production'),

    // Os nomes das tarefas (routes/console.php) que dependem do interruptor acima.
    'external_job_names' => [
        'fetch-meta-ads-metrics', 'dispatch-meta-account-insights-sync', 'dispatch-meta-ad-insights-sync',
        'refresh-stale-market-aggregates', 'sync-carmine-cars', 'restaurant-daily-sync',
        'pingwin-supplier-cc-nightly', 'pingwin-supplier-documents-nightly', 'pingwin-supplier-document-lines-nightly',
        'ocr-pingwin-relink-nightly', 'social-followers-daily',
    ],
];
