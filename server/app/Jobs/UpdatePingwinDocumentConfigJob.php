<?php

namespace App\Jobs;

use App\Models\PingwinDocconfigWrite;
use App\Models\PingwinDocumentConfig;
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
 * XPLENDOR — ⚠️ ESCRITA no PingWin: EDITAR o maindataset de um documento ATIVO (Fase D1).
 * Corre no worker (docker socket). O Python preserva as 14 filhas + additionalfields da
 * releitura viva e confirma por releitura. SÓ se persisted=true é que atualizamos o
 * ESPELHO (as colunas do maindataset + raw; as filhas não mudam na D1). tries=1.
 */
class UpdatePingwinDocumentConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $companyId, public int $writeId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(15)->expireAfter(900)];
    }

    public function handle(PingwinService $pingwin, AlertService $alerts): void
    {
        $write = PingwinDocconfigWrite::where('company_id', $this->companyId)->find($this->writeId);
        if (! $write || $write->status !== 'a_criar') {
            return;
        }

        Log::info('[PingWin Editar Documento] Job iniciado', ['company_id' => $this->companyId, 'write_id' => $this->writeId, 'doc' => $write->docconfig_id]);

        try {
            $result = $pingwin->updateDocumentConfig(
                $this->companyId, (string) $write->docconfig_id,
                (array) ($write->fields ?? []), (array) ($write->children ?? []), (array) ($write->docaccount ?? [])
            );
        } catch (\Throwable $e) {
            $this->markError($write, $e->getMessage(), $alerts);
            Log::warning('[PingWin Editar Documento] Falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);

            return;
        }

        if (! ($result['persisted'] ?? false)) {
            $msg = $result['error'] ?? 'A edição não foi confirmada por releitura.';
            $this->markError($write, $msg, $alerts);
            Log::error('[PingWin Editar Documento] NÃO persistiu', ['write_id' => $this->writeId, 'capture' => $result['capture'] ?? null]);

            return;
        }

        $this->updateMirror((string) $write->docconfig_id, $result);

        $write->update(['status' => 'ok', 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Documento atualizado no PingWin',
            message: 'O documento «' . $write->description . '» foi atualizado no PingWin.',
            severity: 'low',
            detailPath: '/restauracao/documentos',
        );
        Log::info('[PingWin Editar Documento] Concluído', ['write_id' => $this->writeId, 'doc' => $write->docconfig_id]);
    }

    /** Atualiza o ESPELHO com o maindataset CONFIRMADO (reread). As filhas não mudam na D1. */
    private function updateMirror(string $docId, array $result): void
    {
        $doc = PingwinDocumentConfig::where('company_id', $this->companyId)->where('external_id', $docId)->first();
        if (! $doc) {
            return;
        }
        $raw = $result['raw'] ?? [];
        $main = $raw['maindataset'][0] ?? [];
        if (empty($main)) {
            return;
        }
        $s = static fn ($v) => ($v === null || $v === '') ? null : (string) $v;
        $update = [
            'description'        => $main['description'] ?? $doc->description,
            'taxscenario_id'     => $s($main['taxscenario_id'] ?? null),
            'doctype_id'         => $s($main['doctype_id'] ?? null),
            'docfiscaltype_id'   => $s($main['docfiscaltype_id'] ?? null),
            'default_paycond_id' => $s($main['default_paycond_id'] ?? null),
            'stock_signal'       => $s($main['stock_signal'] ?? null),
            'settled'            => (int) ($main['settled'] ?? 0) === 1,
            'docseries_id'       => $s($main['docseries_id'] ?? null),
            'raw'                => $main,              // cast array → re-encode
            'rich_synced_at'     => now(),
        ];
        // D2a: refresca TODAS as 14 filhas + additionalfields do reread (as editadas mudaram;
        // as outras vieram verbatim do vivo). Mantém o espelho coerente com o PingWin.
        foreach (PingwinDocumentConfig::RICH_JSON_COLUMNS as $col) {
            if ($col === 'raw' || $col === 'options') {
                continue;   // raw já tratado; options não mudam numa edição
            }
            if ($col === 'additionalfields_maindataset') {
                $update[$col] = $raw['additionalfields.maindataset'] ?? null;
            } elseif ($col === 'additionalfields_storedataset') {
                $update[$col] = $raw['_storedataset'] ?? null;
            } elseif (array_key_exists($col, $raw)) {
                $update[$col] = $raw[$col];   // as 14 filhas (nome = chave do servidor)
            }
        }
        $doc->update($update);
    }

    private function markError(PingwinDocconfigWrite $write, string $message, AlertService $alerts): void
    {
        $write->update(['status' => 'erro', 'error_message' => mb_substr($message, 0, 800), 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao editar o documento',
            message: 'Não foi possível editar o documento «' . $write->description . '» no PingWin: ' . mb_substr($message, 0, 200),
            severity: 'high',
            detailPath: '/restauracao/documentos',
        );
    }

    public function failed(\Throwable $e): void
    {
        $write = PingwinDocconfigWrite::find($this->writeId);
        if ($write && $write->status === 'a_criar') {
            $write->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 800), 'finished_at' => now()]);
        }
        Log::error('[PingWin Editar Documento] Job falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);
    }
}
