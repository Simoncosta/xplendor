<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\AggregateCarPerformanceMetricsJob;
use App\Jobs\RecalculateAllCarScoresJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Agrega views e leads de todos os carros todos os dias às 00:30
Schedule::job(new AggregateCarPerformanceMetricsJob())
    ->dailyAt('00:30')
    ->name('aggregate-car-performance-metrics')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[AggregateCarPerformanceMetrics] Job falhou no scheduler');
    });

// Corre às 00:35 — 5 min depois do AggregateCarPerformanceMetrics (00:30)
// para garantir que os dados de sessions/leads já estão actualizados
Schedule::job(new RecalculateAllCarScoresJob())
    ->dailyAt('00:35')
    ->name('recalculate-all-car-scores')
    ->withoutOverlapping();
