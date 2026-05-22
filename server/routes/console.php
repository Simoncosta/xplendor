<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\{
    AggregateCarPerformanceMetricsJob,
    GenerateDailyAlertsEmailJob,
    GenerateWeeklyMarketingIdeasJob,
    RecalculateAllCarScoresJob,
    RefreshStaleMarketAggregatesJob,
    FetchMetaAdsMetricsJob,
    SyncCarmineCarsJob,
};

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 00:30 — agrega dados comportamentais (views, leads, interactions)
Schedule::job(new AggregateCarPerformanceMetricsJob())
    ->hourly()
    ->name('aggregate-car-performance-metrics')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[AggregateCarPerformanceMetrics] Job falhou no scheduler');
    });

// 00:45 — puxa dados do Meta Ads (spend, impressions, clicks)
Schedule::job(new FetchMetaAdsMetricsJob())
    ->everyThirtyMinutes()
    ->name('fetch-meta-ads-metrics')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[FetchMetaAdsMetrics] Job falhou no scheduler');
    });

// 01:00 — recalcula IPS com todos os dados completos (comportamentais + paid)
Schedule::job(new RecalculateAllCarScoresJob())
    ->dailyAt('01:00')
    ->name('recalculate-all-car-scores')
    ->withoutOverlapping();


// Schedule de geração automática de ideias de marketing — DESACTIVADO em 2026-05-22.
// Motivo: a página /cars/:id/marketing não tinha uso activo suficiente para justificar
// chamadas semanais à OpenAI para 60+ viaturas. O job e o service permanecem funcionais
// para accionamento manual via botão no UI. Reactivar quando feature for adoptada
// pela equipa comercial dos clientes.
// Schedule::job(new GenerateWeeklyMarketingIdeasJob())->weeklyOn(1, '03:00')->withoutOverlapping();

// Refresh nocturno de aggregates de mercado.
// Limita a 20 viaturas/noite para evitar bloqueio do Standvirtual.
// Refresh apenas para aggregates com updated_at > 7 dias OU sem aggregate.
// Apenas car/motorhome (scraper não suporta caravan/motorcycle).
// Em 14 dias cobre ~280 viaturas — suficiente para a escala actual.
// Prioridade às viaturas sem aggregate algum (NOT EXISTS check).
Schedule::job(new RefreshStaleMarketAggregatesJob())
    ->dailyAt('03:30')
    ->name('refresh-stale-market-aggregates')
    ->withoutOverlapping(60);

Schedule::job(new GenerateDailyAlertsEmailJob())
    ->dailyAt('09:00')
    ->name('generate-daily-alerts-email')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[DailyAlerts] Job falhou no scheduler');
    });

Schedule::job(new SyncCarmineCarsJob())
    ->hourly()
    ->name('sync-carmine-cars')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Carmine Sync] Job falhou no scheduler');
    });
