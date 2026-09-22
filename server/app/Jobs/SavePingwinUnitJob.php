<?php

namespace App\Jobs;

use App\Models\PingwinUnitCreation;
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
 * XPLENDOR — ⚠️ ESCRITA: GRAVAR uma unidade (EDIT,SAVE): EDITAR (deleted=0) ou
 * ANULAR (deleted=1). Reutiliza a auditoria/poll das criações (action='edit'|'anular').
 * Corre no worker (docker socket). Confirmação já dada a montante. Expõe o erro REAL.
 */
class SavePingwinUnitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;      // escrita: não repetir cegamente
    public int $timeout = 120;

    public function __construct(public int $companyId, public int $writeId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(15)->expireAfter(300)];
    }

    public function handle(PingwinService $pingwin, AlertService $alerts): void
    {
        $write = PingwinUnitCreation::where('company_id', $this->companyId)->find($this->writeId);
        if (! $write || $write->status !== 'a_criar' || ! $write->unit_id) {
            return;
        }
        $isAnular = $write->action === 'anular';

        Log::info('[PingWin Gravar Unidade] Job iniciado', ['company_id' => $this->companyId, 'write_id' => $this->writeId, 'action' => $write->action]);

        // Editar → envia os campos novos; Anular → só a flag deleted (mantém o resto).
        $changes = [];
        if (! $isAnular) {
            $changes = array_filter([
                'description'      => $write->description,
                'shortname'        => $write->shortname,
                'parent_id'        => $write->parent_pingwin_id,
                'parent_qnt'       => $write->parent_qnt,
                'net_weight'       => $write->net_weight,
                'warn_maxsale_qnt' => $write->warn_maxsale_qnt,
            ], fn ($v) => $v !== null && $v !== '');
            // Checkboxes: 0 é válido (preservar).
            if ($write->frac_unit !== null) {
                $changes['frac_unit'] = $write->frac_unit ? 1 : 0;
            }
            if ($write->external_measure !== null && $write->external_measure !== '') {
                $changes['external_measure'] = (int) $write->external_measure;
            }
        }

        try {
            $saved = $pingwin->saveUnit($this->companyId, (int) $write->unit_id, $changes, $isAnular);
        } catch (\Throwable $e) {
            $write->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 800), 'finished_at' => now()]);
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: $isAnular ? 'Falha ao anular a unidade' : 'Falha ao alterar a unidade',
                message: 'Não foi possível gravar a unidade «' . $write->description . '» no PingWin: ' . mb_substr($e->getMessage(), 0, 200),
                severity: 'high',
                detailPath: '/restauracao/unidades',
            );
            Log::warning('[PingWin Gravar Unidade] Falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);

            return;
        }

        $write->update(['status' => 'criada', 'pingwin_id' => (string) ($saved['id'] ?? ''), 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: $isAnular ? 'Unidade anulada no PingWin' : 'Unidade alterada no PingWin',
            message: 'A unidade «' . $write->description . '» foi ' . ($isAnular ? 'anulada' : 'alterada') . ' no PingWin.',
            severity: 'low',
            detailPath: '/restauracao/unidades',
        );
        Log::info('[PingWin Gravar Unidade] Concluído', ['write_id' => $this->writeId]);
    }

    public function failed(\Throwable $e): void
    {
        $write = PingwinUnitCreation::find($this->writeId);
        if ($write && $write->status === 'a_criar') {
            $write->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 800), 'finished_at' => now()]);
        }
        Log::error('[PingWin Gravar Unidade] Job falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);
    }
}
