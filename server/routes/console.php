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

// As tarefas que chamam serviços externos (config/scheduler.php) só correm com o interruptor
// ligado: sempre em produção; no dev, só com SCHEDULE_EXTERNAL_JOBS=true. Aplica-se no fim do
// ficheiro, pelos nomes, para nenhuma tarefa nova escapar sem querer.

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
// 04:15 (Lisboa) — Fatia 7a: stock do PingWin (SÓ LEITURA: abre, lê e fecha cada artigo; nunca grava).
// Antes da sync de vendas das 05:00. Artigo a artigo, ~0,9 s cada, em lotes de 50 encadeados (a
// estimativa da leitura completa está na entrega da Fatia 7a). Fora do horário de serviço.
Schedule::job(new \App\Jobs\DispatchStockSyncJob())
    ->dailyAt('04:15')
    ->timezone('Europe/Lisbon')
    ->name('pingwin-stock-nightly')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[PingWin Stock] Despacho noturno falhou no scheduler');
    });

Schedule::job(new ScheduledRestaurantSyncJob())
    ->dailyAt('05:00')
    ->timezone('Europe/Lisbon')
    ->name('restaurant-daily-sync')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Restaurant Daily Sync] Job falhou no scheduler');
    });

// 05:45 (Lisboa) — Fatia 1: fichas técnicas do PingWin (SÓ LEITURA: abre, lê e fecha cada ficha;
// nunca grava). Depois da sync de vendas das 05:00 (~7 min) e antes da conta corrente das 06:30:
// ~170 artigos com ficha a ~2,6 s cada ≈ 8 min, em lotes de 25. Fora do horário de serviço.
Schedule::job(new \App\Jobs\DispatchBomSyncJob())
    ->dailyAt('05:45')
    ->timezone('Europe/Lisbon')
    ->name('pingwin-boms-nightly')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[PingWin Fichas] Despacho noturno falhou no scheduler');
    });

// Segunda-feira 01:30 (Lisboa) — os ids das unidades (base, compra, venda e stock) em todos os artigos
// ativos (SÓ LEITURA: o OPEN e o CLOSE da leitura do stock, artigo a artigo, em lotes de 50). Depois da
// leitura do catálogo de domingo e fora do horário de serviço: ~1500 artigos a ~0,9 s ≈ 25 min.
Schedule::job(new \App\Jobs\SyncArticleUnitsJob())
    ->weeklyOn(1, '01:30')
    ->timezone('Europe/Lisbon')
    ->name('pingwin-article-units-weekly')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[PingWin Unidades] Despacho semanal falhou no scheduler');
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

// 04:30 (Lisboa) — Auditoria v2-C · C3: documentos de caixa do PingWin (SÓ LEITURA): os dois
// últimos dias fechados, um dia e um tipo de cada vez, fora do horário de serviço.
Schedule::job(new \App\Jobs\SyncPingwinPosDocumentsJob())
    ->dailyAt('04:30')
    ->timezone('Europe/Lisbon')
    ->name('pingwin-pos-documents-nightly')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[PingWin Caixa] Leitura noturna falhou no scheduler');
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

// 08:00 (Lisboa) — F2b: mapa de aprendizagem fornecedor+código → artigo (F4 + fichas dos
// artigos) e nova ligação das linhas das faturas por validar. DEPOIS das linhas da F4 (07:15,
// até ~6 min) e da revisão F3 (07:45). Só a nossa BD.
Schedule::job(new \App\Jobs\RefreshSupplierArticleMapJob())
    ->dailyAt('08:00')
    ->timezone('Europe/Lisbon')
    ->name('ocr-article-map-nightly')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[OCR Artigos] Atualização noturna do mapa falhou no scheduler');
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

// 03:50 (Lisboa) — F2c: apaga do disco os ficheiros das faturas OCR apagadas há mais de 30 dias
// (depois da retenção dos media das 03:40).
Schedule::job(new \App\Jobs\PurgeDeletedOcrFilesJob())
    ->dailyAt('03:50')
    ->timezone('Europe/Lisbon')
    ->name('ocr-purge-deleted-files')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[OCR] Purga dos ficheiros apagados falhou no scheduler');
    });

// 04:00 (Lisboa) — Ficheiros: apaga de vez o que está na reciclagem há mais de 30 dias (interna).
Schedule::job(new \App\Jobs\PurgeFilesRecycleJob())
    ->dailyAt('04:00')
    ->timezone('Europe/Lisbon')
    ->name('files-purge-recycle')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Ficheiros] Reciclagem falhou no scheduler');
    });

// 04:10 (Lisboa) — Documentos para financiamento: aviso aos administradores 7 dias antes e
// apagamento automático N dias depois do último envio (por empresa); links nunca usados (interna).
Schedule::job(new \App\Jobs\PurgeFinancingDocumentsJob())
    ->dailyAt('04:10')
    ->timezone('Europe/Lisbon')
    ->name('financing-retention')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Financiamento] Retenção falhou no scheduler');
    });

// De 5 em 5 minutos — Antivírus (ClamAV) dos documentos de financiamento: aviso ao root se não
// responder há mais de 15 minutos; volta a pôr na fila as análises em atraso. Interna (rede do
// Docker). Só com ANTIVIRUS_DRIVER=clamav.
Schedule::command('antivirus:watch')
    ->everyFiveMinutes()
    ->when(fn () => config('financing.antivirus.driver') === 'clamav')
    ->name('antivirus-watch')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Antivírus] A vigilância falhou no scheduler');
    });

// 04:40 (Lisboa) — Ficheiros: regista os ficheiros novos dos módulos nas pastas do sistema
// (empresas com o módulo ligado; nada é copiado). Interna.
Schedule::command('files:catalog --execute')
    ->dailyAt('04:40')
    ->timezone('Europe/Lisbon')
    ->name('files-catalog-nightly')
    ->withoutOverlapping();

// 05:20 (Lisboa): Bússola do stand, os sinais das empresas com o interruptor ligado, só com os
// dados já guardados; as frases da IA vão a seguir, como na restauração. Externa (P2 do fecho):
// chega à IA, por isso fica desligada no dev pelo SCHEDULE_EXTERNAL_JOBS.
Schedule::call(function () {
    \App\Models\Company::where('bussola_stand_enabled', true)->pluck('id')
        ->each(fn ($id) => \App\Jobs\RecomputeStandCompassJob::dispatch((int) $id));
})
    ->dailyAt('05:20')
    ->timezone('Europe/Lisbon')
    ->name('bussola-stand-nightly')
    ->withoutOverlapping();

// 05:10 (Lisboa): Site do consultor, as contagens de interesse dos imóveis (contactos e visitas
// dos últimos 30 dias) a partir do site, antes da Bússola do imobiliário. Interna (só a base).
Schedule::command('consultor:interest-sync')
    ->dailyAt('05:10')
    ->timezone('Europe/Lisbon')
    ->name('consultor-interest-nightly')
    ->withoutOverlapping();

// 05:25 (Lisboa): Bússola do imobiliário, os sinais das empresas com o módulo imoveis (as frases
// da IA vão a seguir). Externa (P2 do fecho): chega à IA, por isso fica desligada no dev.
Schedule::call(fn () => \App\Services\Compass\Imoveis\ImoveisSignalService::recomputeAll())
    ->dailyAt('05:25')
    ->timezone('Europe/Lisbon')
    ->name('bussola-imoveis-nightly')
    ->withoutOverlapping();

// Dia 25 de cada mês, 04:50 (Lisboa): a referência do INE do imobiliário (valor mediano das vendas
// por m²; trimestral, o comando não repete um período já gravado). Externa (lê o INE) e só com
// alguma empresa com o módulo imoveis (--if-used): sem imóveis, não há pedidos.
Schedule::command('ine:housing-prices --execute --if-used')
    ->monthlyOn(25, '04:50')
    ->timezone('Europe/Lisbon')
    ->name('ine-housing-prices-monthly')
    ->withoutOverlapping();

// A cada 15 minutos: Radar de oportunidade, a recolha dos feeds públicos (Google Trends, jornais,
// BCE). Externa (lê fontes na Internet): desligada no dev pelo SCHEDULE_EXTERNAL_JOBS. O teto de
// 4 pedidos por fonte e por hora é do próprio Radar (RadarCollector).
Schedule::job(new \App\Jobs\CollectRadarFeedsJob())
    ->everyFifteenMinutes()
    ->name('radar-collect-feeds')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Radar] Recolha dos feeds falhou no scheduler');
    });

// 03:20 (Lisboa): Radar, apaga os itens, as tendências e as recolhas com mais de 30 dias (interna).
Schedule::job(new \App\Jobs\PurgeRadarItemsJob())
    ->dailyAt('03:20')
    ->timezone('Europe/Lisbon')
    ->name('radar-purge-items')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Radar] Apagamento aos 30 dias falhou no scheduler');
    });

// 09:10 (Lisboa): Parceiro 2, o aviso para renovar a ligação da Meta da agência (10 dias antes do fim)
// e a marca de expirada. Interna: só lê a base de dados.
Schedule::job(new \App\Jobs\WarnAgencyMetaRenewalJob())
    ->dailyAt('09:10')
    ->timezone('Europe/Lisbon')
    ->name('agency-meta-renewal')
    ->withoutOverlapping();

// 03:30 (Lisboa): Radar, o histórico das reações guarda 12 meses; apaga o que é mais antigo (interna).
Schedule::job(new \App\Jobs\PurgeRadarReactionHistoryJob())
    ->dailyAt('03:30')
    ->timezone('Europe/Lisbon')
    ->name('radar-purge-history')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Radar] Apagamento do histórico das reações falhou no scheduler');
    });

// 08:30 (Lisboa) — AUDITORIA da restauração (Fatia 2): todas as regras da área, para as empresas
// com o PingWin ligado. Depois dos jobs do PingWin da madrugada (o último é às 08:00). Só lê o
// espelho e as tabelas da XPLENDOR: é interna (corre também no dev).
Schedule::job(new \App\Jobs\DispatchNightlyAuditJob())
    ->dailyAt('08:30')
    ->timezone('Europe/Lisbon')
    ->name('audit-restaurant-nightly')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Auditoria] Despacho noturno falhou no scheduler');
    });

// 09:15 (Lisboa) — AUDITORIA do tracking dos sites (Fatia 2, Parte B): as contagens do GA4 do dia
// anterior e da XPLENDOR (28 dias) e as regras do tracking. Chama o GA4: é externa.
Schedule::job(new \App\Jobs\TrackingHealthDailyJob())
    ->dailyAt('09:15')
    ->timezone('Europe/Lisbon')
    ->name('tracking-health-daily')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Auditoria] Tracking diário falhou no scheduler');
    });

// 09:30 e 18:30 (Lisboa) — Fatia 6a: as reservas dos próximos 30 dias (CoverManager, SÓ LEITURA, um
// pedido por loja) e, a seguir, a previsão e o erro medido. De manhã (depois das vendas da noite) e ao
// fim da tarde (antes do jantar), para "amanhã" usar as reservas mais recentes.
foreach (['restaurant-reservations-morning' => '09:30', 'restaurant-reservations-evening' => '18:30'] as $name => $at) {
    Schedule::job(new \App\Jobs\SyncFutureReservationsJob())
        ->dailyAt($at)
        ->timezone('Europe/Lisbon')
        ->name($name)
        ->withoutOverlapping()
        ->onFailure(function () {
            \Illuminate\Support\Facades\Log::error('[Previsão] Leitura das reservas falhou no scheduler');
        });
}

// De 5 em 5 minutos — Auditoria: uma corrida sem progresso há mais de 30 minutos (o worker morreu a meio)
// passa a "falhou", com o passo onde parou. Interna (não chama nada lá fora).
Schedule::call(fn () => app(\App\Audit\AuditRunService::class)->expireStale())
    ->everyFiveMinutes()
    ->name('audit-expire-stale-runs')
    ->withoutOverlapping();

// De minuto a minuto: F2.2, publica as publicações automáticas cuja hora chegou (Instagram e Facebook). Com o
// cliente simulado é interna; em produção está desligada (driver "disabled") até ao cliente real da F2.4.
Schedule::job(new \App\Jobs\PublishDueSocialPublicationsJob())
    ->everyMinute()
    ->name('social-publish-due')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Publicação automática] O publicador falhou no scheduler');
    });

// De minuto a minuto — Monitorização: sinal de vida do scheduler (bate já) e do worker (um job na fila,
// que só bate quando o worker o corre). Interna.
Schedule::call(fn () => \App\Services\Health\SystemHealth::beat('scheduler'))
    ->everyMinute()
    ->name('health-heartbeat-scheduler');

Schedule::job(new \App\Jobs\WorkerHeartbeatJob())
    ->everyMinute()
    ->name('health-heartbeat-worker');

// De 5 em 5 minutos — Monitorização: base de dados, Redis, antivírus, disco e os sinais de vida; aviso
// ao root (email direto, sem fila) quando uma falha dura mais de 10 minutos e quando volta. Interna.
Schedule::command('health:watch')
    ->everyFiveMinutes()
    ->name('health-watch')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Saúde] A vigilância falhou no scheduler');
    });

// Interruptor das chamadas externas (ver o topo do ficheiro e config/scheduler.php).
foreach (Schedule::events() as $event) {
    if (in_array($event->description, (array) config('scheduler.external_job_names'), true)) {
        $event->when(fn () => \App\Support\ExternalCalls::allowed());
    }
}
