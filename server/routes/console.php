<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\AggregateCarPerformanceMetricsJob;
use Illuminate\Support\Facades\Schedule;

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
