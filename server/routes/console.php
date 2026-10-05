<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\{
    AggregateCarPerformanceMetricsJob,
    GenerateDailyAlertsEmailJob,
    MarkStaleAggregatesAsErrorJob,
    RecalculateAllCarScoresJob,
    RefreshStaleMarketAggregatesJob,
    FetchMetaAdsMetricsJob,
    ScheduledRestaurantSyncJob,
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

// 01:15 — Meta Ads AO NÍVEL DA CONTA (todas as verticais): últimos 3 dias + hoje
// por integração (a Meta ajusta a atribuição com atraso). Regista o estado de TODAS
// as integrações (token expirado / conta por escolher). Não toca no job por carro.
Schedule::job(new \App\Jobs\DispatchMetaAccountInsightsSyncJob())
    ->dailyAt('01:15')
    ->name('dispatch-meta-account-insights-sync')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[MetaAccountInsights] Despachante falhou no scheduler');
    });

// 01:45 — Meta Ads POR ANÚNCIO: últimos 3 dias + hoje por integração, e reconstrói
// o gasto por viatura (tag [id:N] no nome do anúncio). Depois da ingestão por conta.
Schedule::job(new \App\Jobs\DispatchMetaAdInsightsSyncJob())
    ->dailyAt('01:45')
    ->name('dispatch-meta-ad-insights-sync')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[MetaAdInsights] Despachante falhou no scheduler');
    });

// 02:30 — despesas automáticas do gasto Meta (mês atual e anterior), depois da
// ingestão por anúncio das 01:45. Rede de segurança: o job da ingestão já projeta.
Schedule::job(new \App\Jobs\ProjectMetaExpensesJob())
    ->dailyAt('02:30')
    ->name('project-meta-expenses')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[MetaExpenses] Projeção falhou no scheduler');
    });

// 01:00 — recalcula IPS com todos os dados completos (comportamentais + paid)
Schedule::job(new RecalculateAllCarScoresJob())
    ->dailyAt('01:00')
    ->name('recalculate-all-car-scores')
    ->withoutOverlapping();


// Marca aggregates em pending/running há mais de 10 min como error.
// Elimina zombies silenciosos quando o worker falha ou o scraper congela.
Schedule::job(new MarkStaleAggregatesAsErrorJob())
    ->everyFiveMinutes()
    ->name('mark-stale-aggregates-as-error')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[MarkStaleAggregates] Job falhou no scheduler');
    });

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

// 05:00 (Lisboa) — sync automático das vendas/reservas do DIA ANTERIOR de todas as
// empresas de restauração com integração ativa (PingWin + CoverManager, em série).
Schedule::job(new ScheduledRestaurantSyncJob())
    ->dailyAt('05:00')
    ->timezone('Europe/Lisbon')
    ->name('restaurant-daily-sync')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Restaurant Daily Sync] Job falhou no scheduler');
    });

// 00:15 (Lisboa): expira os orçamentos enviados com a validade de 30 dias ultrapassada.
// Rascunhos nunca expiram.
Schedule::job(new \App\Jobs\ExpireQuotesJob())
    ->dailyAt('00:15')
    ->timezone('Europe/Lisbon')
    ->name('quotes-expire')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Quotes Expire] Job falhou no scheduler');
    });

// A cada 5 minutos: publica os artigos do blog aprovados cuja data chegou.
Schedule::job(new \App\Jobs\PublishScheduledBlogsJob())
    ->everyFiveMinutes()
    ->timezone('Europe/Lisbon')
    ->name('blogs-publish-scheduled')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Blog Publish] Job falhou no scheduler');
    });
