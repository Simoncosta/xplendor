<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\{
    AggregateCarPerformanceMetricsJob,
    GenerateWeeklyMarketingIdeasJob,
    RecalculateAllCarScoresJob,
    FetchMetaAdsMetricsJob,
    SyncCarmineCarsJob,
};

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 00:30 — agrega dados comportamentais (views, leads, interactions)
Schedule::job(new AggregateCarPerformanceMetricsJob())
    ->dailyAt('00:30')
    ->name('aggregate-car-performance-metrics')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[AggregateCarPerformanceMetrics] Job falhou no scheduler');
    });

// 00:45 — puxa dados do Meta Ads (spend, impressions, clicks)
Schedule::job(new FetchMetaAdsMetricsJob())
    ->dailyAt('00:45')
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


Schedule::job(new GenerateWeeklyMarketingIdeasJob())
    ->mondays()
    ->at('03:00')
    ->name('generate-weekly-marketing-ideas')
    ->withoutOverlapping();

Schedule::job(new SyncCarmineCarsJob())
    ->hourly()
    ->name('sync-carmine-cars')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Carmine Sync] Job falhou no scheduler');
    });
