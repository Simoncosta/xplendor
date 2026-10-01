<?php

namespace App\Jobs;

use App\Models\PingwinPaymentCondition;
use App\Models\PingwinPaycondWrite;
use App\Services\AlertService;
use App\Services\PingwinService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — ⚠️ ESCRITA no PingWin: ANULAR uma condição de pagamento ATIVA (Fatia 2c,
 * soft-delete). Corre no worker (docker socket). O Python confirma por releitura (sai do
 * STATE 0, entra no STATE 1). SÓ se voided_confirmed=true é que marcamos is_active=false
 * no ESPELHO. Serializado por empresa. tries=1 (não repete cego).
 */
class VoidPingwinPaymentConditionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(public int $companyId, public int $writeId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(15)->expireAfter(600)];
    }

    public function handle(PingwinService $pingwin, AlertService $alerts): void
    {
        $write = PingwinPaycondWrite::where('company_id', $this->companyId)->find($this->writeId);
        if (! $write || $write->status !== 'a_criar') {
            return; // já processada ou inexistente (idempotência básica)
        }

        Log::info('[PingWin Anular Condição] Job iniciado', ['company_id' => $this->companyId, 'write_id' => $this->writeId, 'paycond_id' => $write->paycond_id]);

        try {
            $result = $pingwin->voidPaymentCondition($this->companyId, (string) $write->paycond_id);
        } catch (\Throwable $e) {
            $this->markError($write, $e->getMessage(), $alerts);
            Log::warning('[PingWin Anular Condição] Falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);

            return;
        }

        // ⚠️ http 200 ≠ anulado. Só voided_confirmed=true (releitura STATE 1) vale.
        if (! ($result['voided_confirmed'] ?? false)) {
            $msg = $result['error'] ?? 'A anulação não foi confirmada por releitura (o registo não saiu dos ativos).';
            $this->markError($write, $msg, $alerts);
            Log::error('[PingWin Anular Condição] NÃO confirmado', ['write_id' => $this->writeId, 'result' => $result]);

            return;
        }

        // Só AGORA se marca inativo no espelho (soft-delete confirmado).
        PingwinPaymentCondition::where('company_id', $this->companyId)
            ->where('pingwin_id', (string) $write->paycond_id)
            ->update(['is_active' => false, 'synced_at' => now()]);

        $write->update(['status' => 'ok', 'pingwin_id' => (string) $write->paycond_id, 'finished_at' => now()]);

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Condição de pagamento anulada no PingWin',
            message: 'A condição «' . $write->description . '» foi anulada no PingWin.',
            severity: 'low',
            detailPath: '/restauracao/condicoes-pagamento',
        );
        Log::info('[PingWin Anular Condição] Concluído', ['write_id' => $this->writeId, 'paycond_id' => $write->paycond_id]);
    }

    private function markError(PingwinPaycondWrite $write, string $message, AlertService $alerts): void
    {
        $write->update(['status' => 'erro', 'error_message' => mb_substr($message, 0, 800), 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao anular a condição de pagamento',
            message: 'Não foi possível anular a condição «' . $write->description . '» no PingWin: ' . mb_substr($message, 0, 200),
            severity: 'high',
            detailPath: '/restauracao/condicoes-pagamento',
        );
    }

    public function failed(\Throwable $e): void
    {
        $write = PingwinPaycondWrite::find($this->writeId);
        if ($write && $write->status === 'a_criar') {
            $write->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 800), 'finished_at' => now()]);
        }
        Log::error('[PingWin Anular Condição] Job falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);
    }
}
