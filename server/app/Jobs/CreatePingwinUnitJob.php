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
 * XPLENDOR — ⚠️ 1ª ESCRITA no PingWin: CRIAR uma unidade (Action NEW). Corre no
 * worker (tem o docker socket, necessário para o docker exec ao scraper). A
 * confirmação do utilizador já foi dada a montante (a UI perguntou + o controller
 * exigiu confirm). Atualiza o registo de auditoria (criada|erro) com o resultado
 * REAL e notifica no sino. Serializado por empresa (não sobrepõe outras ações).
 */
class CreatePingwinUnitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // escrita: NÃO repetir cegamente (evita duplicar no PingWin)
    public int $timeout = 120;

    public function __construct(public int $companyId, public int $creationId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(15)->expireAfter(300)];
    }

    public function handle(PingwinService $pingwin, AlertService $alerts): void
    {
        $creation = PingwinUnitCreation::where('company_id', $this->companyId)->find($this->creationId);
        if (! $creation || $creation->status !== 'a_criar') {
            return; // já processada ou inexistente (idempotência básica — não recria)
        }

        Log::info('[PingWin Criar Unidade] Job iniciado', ['company_id' => $this->companyId, 'creation_id' => $this->creationId]);

        $payload = array_filter([
            'description'      => $creation->description,
            'shortname'        => $creation->shortname,
            'parent_id'        => $creation->parent_pingwin_id,
            'parent_qnt'       => $creation->parent_qnt,
            'net_weight'       => $creation->net_weight,
            'warn_maxsale_qnt' => $creation->warn_maxsale_qnt,
        ], fn ($v) => $v !== null && $v !== '');
        // Checkboxes: 0 é válido (não usar array_filter, que o descartaria).
        if ($creation->frac_unit !== null) {
            $payload['frac_unit'] = $creation->frac_unit ? 1 : 0;
        }
        if ($creation->external_measure !== null && $creation->external_measure !== '') {
            $payload['external_measure'] = (int) $creation->external_measure;
        }

        try {
            $created = $pingwin->createUnit($this->companyId, $payload);
        } catch (\Throwable $e) {
            // Expõe o motivo REAL do PingWin no registo (não genérico).
            $creation->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 800), 'finished_at' => now()]);
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Falha ao criar a unidade',
                message: 'Não foi possível criar a unidade «' . $creation->description . '» no PingWin: ' . mb_substr($e->getMessage(), 0, 200),
                severity: 'high',
                detailPath: '/restauracao/unidades',
            );
            Log::warning('[PingWin Criar Unidade] Falhou', ['creation_id' => $this->creationId, 'error' => $e->getMessage()]);

            return;
        }

        $creation->update([
            'status'      => 'criada',
            'pingwin_id'  => (string) ($created['id'] ?? ''),
            'finished_at' => now(),
        ]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Unidade criada no PingWin',
            message: 'A unidade «' . $creation->description . '» foi criada no PingWin (id ' . ($created['id'] ?? '?') . ').',
            severity: 'low',
            detailPath: '/restauracao/unidades',
        );
        Log::info('[PingWin Criar Unidade] Concluído', ['creation_id' => $this->creationId, 'pingwin_id' => $created['id'] ?? null]);
    }

    public function failed(\Throwable $e): void
    {
        // Segurança: se o job rebentar fora do try (ex.: timeout), marca erro (não deixa "a_criar" preso).
        $creation = PingwinUnitCreation::find($this->creationId);
        if ($creation && $creation->status === 'a_criar') {
            $creation->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 800), 'finished_at' => now()]);
        }
        Log::error('[PingWin Criar Unidade] Job falhou', ['creation_id' => $this->creationId, 'error' => $e->getMessage()]);
    }
}
