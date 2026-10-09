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

// 06:30 (Lisboa) — conta corrente dos fornecedores PingWin (S1, SÓ LEITURA). Depois da
// sync de vendas das 05:00 (~7 min; mais ao domingo com o catálogo completo): margem
// de 1h30. Só despacha lotes de 40 fornecedores para a queue; com um único worker,
// os lotes correm em série e nunca em cima da sync de vendas.
Schedule::job(new \App\Jobs\DispatchSupplierCcSyncJob())
    ->dailyAt('06:30')
    ->timezone('Europe/Lisbon')
    ->name('pingwin-supplier-cc-nightly')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[PingWin CC] Despacho noturno falhou no scheduler');
    });

// 07:00 (Lisboa) — documentos de fornecedor do PingWin (F1, SÓ LEITURA): os últimos 7 dias
// (lançamentos atrasados e anulações recentes). Depois da conta corrente das 06:30 (~1 min
// em lotes); com um único worker, nada corre em cima de outra sync PingWin.
Schedule::job(new \App\Jobs\DispatchSupplierDocumentsSyncJob())
    ->dailyAt('07:00')
    ->timezone('Europe/Lisbon')
    ->name('pingwin-supplier-documents-nightly')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[PingWin Documentos] Despacho noturno falhou no scheduler');
    });

// 07:15 (Lisboa) — LINHAS dos documentos de fornecedor (F4, SÓ LEITURA): os documentos
// fechados novos ou mudados (incremental), em lotes de 50, até 300 por madrugada. Depois da
// lista de documentos das 07:00 (~1 min); o job ainda espera se o run da F1 estiver ativo.
Schedule::job(new \App\Jobs\DispatchSupplierDocumentLinesSyncJob())
    ->dailyAt('07:15')
    ->timezone('Europe/Lisbon')
    ->name('pingwin-supplier-document-lines-nightly')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[PingWin Linhas] Despacho noturno falhou no scheduler');
    });

// 07:45 (Lisboa) — F3: volta a ligar as faturas OCR dos últimos 90 dias aos documentos do
// PingWin, DEPOIS da lista de documentos (07:00) e das linhas (07:15, até ~6 min). Só espelhos
// (+ pesquisa de fornecedor por NIF, só leitura).
Schedule::job(new \App\Jobs\RelinkOcrInvoicesJob())
    ->dailyAt('07:45')
    ->timezone('Europe/Lisbon')
    ->name('ocr-pingwin-relink-nightly')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[OCR↔PingWin] Verificação noturna falhou no scheduler');
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

// 04:30 (Lisboa): seguidores automáticos do Instagram e da Página de Facebook ligados
// nas redes sociais. Só grava quando lê de facto; a leitura ganha ao registo manual do dia.
Schedule::job(new \App\Jobs\ReadSocialFollowersJob())
    ->dailyAt('04:30')
    ->timezone('Europe/Lisbon')
    ->name('social-followers-daily')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Redes sociais] Job de seguidores falhou no scheduler');
    });

// 03:10 (Lisboa): retenção dos links públicos dos orçamentos (aberturas 12 meses,
// respostas do cliente 10 anos).
Schedule::job(new \App\Jobs\PruneQuoteActivityJob())
    ->dailyAt('03:10')
    ->timezone('Europe/Lisbon')
    ->name('quotes-prune-activity')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Orçamentos] Job de retenção falhou no scheduler');
    });

// Diário às 03:40 (Lisboa): retenção dos media da Linha Editorial e aviso de disco acima de 70%.
Schedule::job(new \App\Jobs\MediaRetentionJob())
    ->dailyAt('03:40')
    ->timezone('Europe/Lisbon')
    ->name('media-retention')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Media Retention] Job falhou no scheduler');
    });

// Links de aprovação de conteúdos (F3c): lembretes às 09:00 (Lisboa) e resumo por email a cada 15 minutos.
Schedule::job(new \App\Jobs\ContentReviewRemindersJob())
    ->dailyAt('09:00')
    ->timezone('Europe/Lisbon')
    ->name('content-review-reminders')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Aprovação de conteúdos] Lembretes falharam no scheduler');
    });

Schedule::job(new \App\Jobs\ContentReviewDigestJob())
    ->everyFifteenMinutes()
    ->name('content-review-digest')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Aprovação de conteúdos] Resumo por email falhou no scheduler');
    });

// Linha Editorial (F3d): lista do dia às 08:30 (Lisboa); atrasadas e passagem a Análise a cada 15 minutos.
Schedule::job(new \App\Jobs\EditorialTodayJob())
    ->dailyAt('08:30')
    ->timezone('Europe/Lisbon')
    ->name('editorial-today')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Linha Editorial] Lista de hoje falhou no scheduler');
    });

Schedule::job(new \App\Jobs\EditorialPublishingWatchJob())
    ->everyFifteenMinutes()
    ->name('editorial-publishing-watch')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Linha Editorial] Vigia das publicações falhou no scheduler');
    });

// Cobranças da XPLENDOR: lembretes às 09:00 (Lisboa), no dia do vencimento e às segundas-feiras.
Schedule::job(new \App\Jobs\ChargeRemindersJob())
    ->dailyAt('09:00')
    ->timezone('Europe/Lisbon')
    ->name('charge-reminders')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Cobranças] Lembretes falharam no scheduler');
    });

// Gestão por agências (F1d): pedidos de gestão expiram aos 14 dias (de hora a hora) e o
// arquivo das empresas sem admin nem agência (diário, 04:10 em Lisboa: aviso e apagamento).
Schedule::job(new \App\Jobs\ExpireManagementRequestsJob())
    ->hourly()
    ->name('management-requests-expire')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Gestão por agências] Expirar pedidos falhou no scheduler');
    });

Schedule::job(new \App\Jobs\CompanyArchiveJob())
    ->dailyAt('04:10')
    ->timezone('Europe/Lisbon')
    ->name('company-archive')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Gestão por agências] Arquivo de empresas falhou no scheduler');
    });

Schedule::job(new \App\Jobs\ScrubManagementRequestsJob())
    ->dailyAt('04:20')
    ->timezone('Europe/Lisbon')
    ->name('management-requests-scrub')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Gestão por agências] Apagar dados de pedidos falhou no scheduler');
    });

// Gestão por agências (F1e): no dia 1 de cada mês, a contagem das empresas que contam para cada agência.
Schedule::job(new \App\Jobs\AgencyBillingSnapshotJob())
    ->monthlyOn(1, '00:30')
    ->timezone('Europe/Lisbon')
    ->name('agency-billing-snapshot')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Gestão por agências] Contagem mensal falhou no scheduler');
    });

// Link de configuração do cliente (F1c): passos da Meta iniciados e não concluídos (30 minutos).
Schedule::job(new \App\Jobs\SetupLinkStalledJob())
    ->everyFiveMinutes()
    ->name('setup-link-stalled')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Link de configuração] Verificação dos passos parados falhou no scheduler');
    });
