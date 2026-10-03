<?php

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Services\MetaAdInsightsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza os insights Meta AO NÍVEL DO ANÚNCIO de UMA integração e reconstrói a
 * atribuição por viatura (tag [id:N]). Ver MetaAdInsightsService.
 *
 * Backfill: UM mês por execução (cada execução fica bem abaixo do retry_after da
 * fila); se faltarem meses, o job volta a enfileirar-se para o seguinte. O cursor
 * fica na integração, por isso uma falha a meio retoma onde parou.
 */
class SyncMetaAdInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Mesmas regras do job por conta: as libertações contam como tentativas. */
    public int $tries = 40;

    public int $maxExceptions = 3;

    /** Abaixo do retry_after da fila redis (90s). */
    public int $timeout = 85;

    public int $backoff = 60;

    public function __construct(
        public readonly int $integrationId,
        public readonly string $mode = MetaAdInsightsService::MODE_DAILY,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("meta-ad-insights:{$this->integrationId}"))
            ->releaseAfter(30)
            ->expireAfter(600)];
    }

    public function handle(MetaAdInsightsService $service): void
    {
        $integration = CompanyIntegration::find($this->integrationId);
        if (! $integration || $integration->platform !== 'meta') {
            return;
        }

        $result = $service->sync(
            $integration,
            $this->mode,
            $this->mode === MetaAdInsightsService::MODE_BACKFILL || $integration->ad_insights_backfilled_at === null ? 1 : null
        );

        match ($result['result'] ?? null) {
            // A Meta pediu para abrandar → volta a tentar daqui a 5 min (sem falhar).
            'retryable' => $this->release(300),
            // Faltam meses do backfill → próximo mês num job novo.
            'backfill_in_progress' => self::dispatch($this->integrationId, MetaAdInsightsService::MODE_BACKFILL),
            default => null,
        };
    }

    public function failed(\Throwable $e): void
    {
        $exhausted = $e instanceof MaxAttemptsExceededException;

        CompanyIntegration::where('id', $this->integrationId)->update([
            'ad_insights_sync_status' => $exhausted
                ? MetaAdInsightsService::STATUS_PENDING
                : MetaAdInsightsService::STATUS_FAILED,
            'ad_insights_last_run_at' => now(),
            'ad_insights_error'       => $exhausted
                ? 'Sincronização adiada; nova tentativa no próximo ciclo.'
                : mb_substr('Falha na sincronização Meta por anúncio: ' . $e->getMessage(), 0, 500),
        ]);

        Log::error('SyncMetaAdInsightsJob: falhou', [
            'integration_id' => $this->integrationId,
            'mode'           => $this->mode,
            'exhausted'      => $exhausted,
            'error'          => $e->getMessage(),
        ]);
    }
}
