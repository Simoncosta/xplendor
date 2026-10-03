<?php

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Services\MetaAccountInsightsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza os insights Meta AO NÍVEL DA CONTA de UMA integração
 * (modo backfill = 90 dias; daily = últimos 3 dias + hoje, com recuperação de dias
 * perdidos). Ver MetaAccountInsightsService. NÃO toca no pipeline por carro.
 */
class SyncMetaAccountInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tentativas (as libertações por sobreposição e por throttling contam). Usa-se
     * tries em vez de retryUntil: o retryUntil conta a partir do DISPATCH e um job
     * que ficasse >15 min na fila (fila partilhada) falhava sem nunca correr.
     */
    public int $tries = 40;

    /** Só excepções INESPERADAS contam para falhar de vez (erros da Graph API vêm
     *  estruturados do service e não lançam). */
    public int $maxExceptions = 3;

    /** Abaixo do retry_after da fila redis (90s) — senão o job seria reservado em
     *  duplicado enquanto ainda corre. */
    public int $timeout = 85;

    public int $backoff = 60;

    public function __construct(
        public readonly int $integrationId,
        public readonly string $mode = MetaAccountInsightsService::MODE_DAILY,
    ) {}

    public function middleware(): array
    {
        // Um sync de cada vez por integração; o sobreposto ESPERA a vez (30s).
        return [(new WithoutOverlapping("meta-account-insights:{$this->integrationId}"))
            ->releaseAfter(30)
            ->expireAfter(600)];
    }

    public function handle(MetaAccountInsightsService $service): void
    {
        $integration = CompanyIntegration::find($this->integrationId);
        if (! $integration || $integration->platform !== 'meta') {
            return;
        }

        $result = $service->sync($integration, $this->mode);

        // A Meta pediu para abrandar → volta a tentar daqui a 5 min (sem falhar).
        if (($result['result'] ?? null) === 'retryable') {
            $this->release(300);

            return;
        }

        // Fotografia dos públicos personalizados (motor de recomendações). Só com a
        // ligação utilizável; um erro aqui NUNCA afeta o sync dos insights.
        if (in_array($result['result'] ?? null, ['done', 'failed'], true)) {
            try {
                app(\App\Services\MetaCustomAudiencesService::class)->sync($integration->fresh());
            } catch (\Throwable $e) {
                Log::warning('SyncMetaAccountInsightsJob: públicos não sincronizados', [
                    'integration_id' => $this->integrationId,
                    'error'          => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        // Esgotou tentativas só por esperar a vez/throttling: não é um erro real da
        // conta — fica pendente e o despachante diário volta a tentar.
        $exhausted = $e instanceof MaxAttemptsExceededException;

        CompanyIntegration::where('id', $this->integrationId)->update([
            'insights_sync_status' => $exhausted
                ? MetaAccountInsightsService::STATUS_PENDING
                : MetaAccountInsightsService::STATUS_FAILED,
            'insights_last_run_at' => now(),
            'insights_error'       => $exhausted
                ? 'Sincronização adiada; nova tentativa no próximo ciclo.'
                : mb_substr('Falha na sincronização Meta: ' . $e->getMessage(), 0, 500),
        ]);

        Log::error('SyncMetaAccountInsightsJob: falhou', [
            'integration_id' => $this->integrationId,
            'mode'           => $this->mode,
            'exhausted'      => $exhausted,
            'error'          => $e->getMessage(),
        ]);
    }
}
