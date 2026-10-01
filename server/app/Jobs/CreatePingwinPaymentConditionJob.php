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
 * XPLENDOR — ⚠️ ESCRITA no PingWin: CRIAR uma condição de pagamento (Fatia 2a). Corre
 * no worker (docker socket). O Python confirma por releitura; SÓ se persisted=true é que
 * atualizamos o ESPELHO local (pingwin_payment_conditions) com o raw/confirm do servidor.
 * Serializado por empresa. tries=1 → nunca repete cegamente (não duplica no PingWin).
 */
class CreatePingwinPaymentConditionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;   // escrita: NÃO repetir cegamente
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

        Log::info('[PingWin Criar Condição] Job iniciado', ['company_id' => $this->companyId, 'write_id' => $this->writeId]);

        $fields = [
            'code'        => $write->code,
            'description' => $write->description,
            'discount'    => $write->discount,   // % (número na fronteira)
            'days'        => $write->days,
        ];

        try {
            $result = $pingwin->createPaymentCondition($this->companyId, $fields, (array) ($write->tbdocs_unlinked ?? []));
        } catch (\Throwable $e) {
            $this->markError($write, $e->getMessage(), $alerts);
            Log::warning('[PingWin Criar Condição] Falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);

            return;
        }

        // ⚠️ Mensagem de sucesso ≠ gravado. Só persisted=true (confirmado por releitura) vale.
        if (! ($result['persisted'] ?? false)) {
            $msg = $result['error'] ?? 'A condição não foi confirmada no PingWin após o SAVE (releitura não a encontrou).';
            $this->markError($write, $msg, $alerts);
            Log::error('[PingWin Criar Condição] NÃO persistiu', ['write_id' => $this->writeId, 'capture' => $result['capture'] ?? null]);

            return;
        }

        $this->updateMirror($result);

        $write->update([
            'status'      => 'ok',
            'code'        => (string) ($result['code'] ?? $write->code),
            'paycond_id'  => (string) ($result['pingwin_id'] ?? ''),
            'pingwin_id'  => (string) ($result['pingwin_id'] ?? ''),
            'finished_at' => now(),
        ]);

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Condição de pagamento criada no PingWin',
            message: 'A condição «' . $write->description . '» (code ' . ($result['code'] ?? '?') . ') foi criada no PingWin.',
            severity: 'low',
            detailPath: '/restauracao/condicoes-pagamento',
        );
        Log::info('[PingWin Criar Condição] Concluído', ['write_id' => $this->writeId, 'pingwin_id' => $result['pingwin_id'] ?? null]);
    }

    /** Atualiza (upsert) o espelho pingwin_payment_conditions só após persisted=true. */
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
            title: 'Falha ao criar a condição de pagamento',
            message: 'Não foi possível criar a condição «' . $write->description . '» no PingWin: ' . mb_substr($message, 0, 200),
            severity: 'high',
            detailPath: '/restauracao/condicoes-pagamento',
        );
    }

    /** Estado preso em 'a_criar' = mentira silenciosa (o polling giraria para sempre) → marcar erro. */
    public function failed(\Throwable $e): void
    {
        $write = PingwinPaycondWrite::find($this->writeId);
        if ($write && $write->status === 'a_criar') {
            $write->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 800), 'finished_at' => now()]);
        }
        Log::error('[PingWin Criar Condição] Job falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);
    }
}
