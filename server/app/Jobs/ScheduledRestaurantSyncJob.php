<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — SYNC AUTOMÁTICO diário (05:00 Lisboa) das vendas/reservas do DIA ANTERIOR,
 * para todas as empresas com integração PingWin/CoverManager NÃO-revogada.
 *
 * Fan-out em SÉRIE (uma empresa a seguir à outra — suave às 5h, sem paralelismo): para
 * cada empresa corre o SyncRestaurantJob (o orquestrador provado: PingWin+CoverManager,
 * dia anterior por defeito) via dispatchSync com notify=false → LANÇA em problemas, o que
 * deixa este fan-out CONHECER as falhas sem parar as outras. Corre no WORKER (tem o docker
 * socket que o PingWin precisa). Avisos: sino por empresa em falha + UM resumo ao dono
 * (só quando há falhas — silêncio quando corre tudo bem).
 */
class ScheduledRestaurantSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800; // corre as empresas em série; margem para várias.

    public function __construct(public ?string $date = null) {}

    public function middleware(): array
    {
        // Nunca dois runs agendados em cima um do outro.
        return [(new WithoutOverlapping('scheduled-restaurant-sync'))->expireAfter(3600)];
    }

    /** Empresas com integração PingWin/CoverManager não-revogada (candidatas ao sync). */
    public static function activeCompanyIds(): array
    {
        return CompanyIntegration::whereIn('platform', ['pingwin', 'covermanager'])
            ->where('status', '!=', 'revoked')
            ->distinct()
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function handle(AlertService $alerts): void
    {
        $companyIds = self::activeCompanyIds();
        Log::info('[Scheduled Restaurant Sync] início', [
            'empresas' => count($companyIds),
            'date'     => $this->date ?: 'dia anterior',
        ]);

        $failed = []; // company_id => motivo

        foreach ($companyIds as $companyId) {
            try {
                // SÉRIE: corre agora, aqui, antes de passar à próxima. notify=false → lança
                // em problemas (para os apanharmos); o dia anterior é o default do job.
                SyncRestaurantJob::dispatchSync($companyId, $this->date, false);
            } catch (\Throwable $e) {
                $failed[$companyId] = mb_substr($e->getMessage(), 0, 200);
                Log::warning('[Scheduled Restaurant Sync] empresa falhou', [
                    'company_id' => $companyId, 'error' => $e->getMessage(),
                ]);
                // Sino POR EMPRESA (a própria empresa vê que falhou) — mesmo alerta do failed().
                try {
                    $alerts->createSystemAlert(
                        companyId: $companyId,
                        type: 'warning',
                        title: 'Falha ao atualizar dados',
                        message: 'A sincronização automática de restauração falhou. Podes sincronizar manualmente.',
                        severity: 'high',
                        detailPath: '/restauracao',
                    );
                } catch (\Throwable) { /* o resumo ao dono continua */ }
            }
        }

        Log::info('[Scheduled Restaurant Sync] fim', [
            'processadas' => count($companyIds), 'falhas' => count($failed),
        ]);

        // RESUMO ao dono — só quando há falhas (silêncio quando corre tudo bem).
        if (! empty($failed)) {
            $this->notifyOwners($alerts, $failed);
        }
    }

    /** UM alerta in-app para o(s) dono(s) (role root), listando as empresas que falharam. */
    private function notifyOwners(AlertService $alerts, array $failed): void
    {
        $names = Company::whereIn('id', array_keys($failed))->pluck('fiscal_name', 'id');
        $list = collect(array_keys($failed))
            ->map(fn ($id) => $names[$id] ?? "Empresa #{$id}")
            ->implode(', ');

        $ownerCompanyIds = User::where('role', 'root')->whereNotNull('company_id')
            ->distinct()->pluck('company_id');

        foreach ($ownerCompanyIds as $ownerCompanyId) {
            $alerts->createSystemAlert(
                companyId: (int) $ownerCompanyId,
                type: 'warning',
                title: 'Sync automático: falhas',
                message: count($failed) . ' empresa(s) falharam no sync das 5h: ' . $list,
                severity: 'high',
                detailPath: '/companies',
            );
        }
    }
}
