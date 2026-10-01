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
 * XPLENDOR — ⚠️ ESCRITA no PingWin: EDITAR uma condição de pagamento ATIVA (Fatia 2b).
 * Corre no worker (docker socket). O Python lê a matriz VIVA por id e aplica só as
 * mudanças (preservação de vínculos); confirma por releitura. SÓ se persisted=true é
 * que atualizamos o ESPELHO local. Serializado por empresa. tries=1 (não repete cego).
 */
class UpdatePingwinPaymentConditionJob implements ShouldQueue
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

        Log::info('[PingWin Editar Condição] Job iniciado', ['company_id' => $this->companyId, 'write_id' => $this->writeId, 'paycond_id' => $write->paycond_id]);

        $fields = [];
        if ($write->description !== null) {
            $fields['description'] = $write->description;
        }
        if ($write->discount !== null) {
            $fields['discount'] = $write->discount;   // % (número na fronteira)
        }
        if ($write->days !== null) {
            $fields['days'] = $write->days;
        }

        try {
            $result = $pingwin->updatePaymentCondition(
                $this->companyId, (string) $write->paycond_id, $fields, (array) ($write->tbdocs_changes ?? [])
            );
        } catch (\Throwable $e) {
            $this->markError($write, $e->getMessage(), $alerts);
            Log::warning('[PingWin Editar Condição] Falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);

            return;
        }

        if (! ($result['persisted'] ?? false)) {
            $msg = $result['error'] ?? 'A edição não foi confirmada no PingWin após o SAVE (releitura não bateu).';
            $this->markError($write, $msg, $alerts);
            Log::error('[PingWin Editar Condição] NÃO persistiu', ['write_id' => $this->writeId, 'capture' => $result['capture'] ?? null]);

            return;
        }

        $this->updateMirror($result);

        $write->update([
            'status'      => 'ok',
            'pingwin_id'  => (string) ($result['pingwin_id'] ?? $write->paycond_id),
            'finished_at' => now(),
        ]);

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Condição de pagamento atualizada no PingWin',
            message: 'A condição «' . $write->description . '» foi atualizada no PingWin.',
            severity: 'low',
            detailPath: '/restauracao/condicoes-pagamento',
        );
        Log::info('[PingWin Editar Condição] Concluído', ['write_id' => $this->writeId, 'pingwin_id' => $result['pingwin_id'] ?? null]);
    }

    /** Atualiza o espelho com o estado CONFIRMADO pelo servidor (não o enviado). */
    private function updateMirror(array $result): void
    {
        $pingwinId = (string) ($result['pingwin_id'] ?? '');
        if ($pingwinId === '') {
            return;
        }
        $confirm = $result['confirm'] ?? [];
        $raw = $result['raw'] ?? [];
        $tbdocs = $raw['tbdocs'] ?? null;

        PingwinPaymentCondition::updateOrCreate(
            ['company_id' => $this->companyId, 'pingwin_id' => $pingwinId],
            [
                'code'        => (string) ($confirm['code'] ?? ''),
                'description' => (string) ($confirm['description'] ?? ''),
                'discount'    => $confirm['discount'] ?? null,
                'days'        => $confirm['days'] ?? null,
                'is_active'   => (int) ($confirm['deleted'] ?? 0) === 0,
                'tbdocs'      => is_array($tbdocs) ? $tbdocs : null,
                'raw'         => is_array($raw) ? $raw : null,
                'synced_at'   => now(),
            ]
        );
    }

    private function markError(PingwinPaycondWrite $write, string $message, AlertService $alerts): void
    {
        $write->update(['status' => 'erro', 'error_message' => mb_substr($message, 0, 800), 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao editar a condição de pagamento',
            message: 'Não foi possível editar a condição «' . $write->description . '» no PingWin: ' . mb_substr($message, 0, 200),
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
        Log::error('[PingWin Editar Condição] Job falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);
    }
}
