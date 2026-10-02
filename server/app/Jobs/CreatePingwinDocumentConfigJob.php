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
 * XPLENDOR — ⚠️ ESCRITA no PingWin: CRIAR um documento novo (Fase D3). Corre no worker.
 * O Python cria (maindataset preenchido + 14 filhas no template), lê o id FINAL da resposta
 * e confirma por releitura (sem exigir code igual — truncagem). SÓ se persisted=true é que
 * inserimos o novo documento no ESPELHO (a partir do reread). tries=1.
 */
class CreatePingwinDocumentConfigJob implements ShouldQueue
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

        Log::info('[PingWin Criar Documento] Job iniciado', ['company_id' => $this->companyId, 'write_id' => $this->writeId]);

        try {
            $result = $pingwin->createDocumentConfig($this->companyId, (array) ($write->fields ?? []));
        } catch (\Throwable $e) {
            $this->markError($write, $e->getMessage(), $alerts);
            Log::warning('[PingWin Criar Documento] Falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);

            return;
        }

        if (! ($result['persisted'] ?? false)) {
            $this->markError($write, $result['error'] ?? 'A criação não foi confirmada por releitura.', $alerts);
            Log::error('[PingWin Criar Documento] NÃO persistiu', ['write_id' => $this->writeId, 'capture' => $result['capture'] ?? null]);

            return;
        }

        $finalId = (string) ($result['pingwin_id'] ?? '');
        $this->insertMirror($finalId, $result);

        $write->update(['status' => 'ok', 'docconfig_id' => $finalId, 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Documento criado no PingWin',
            message: 'O documento «' . $write->description . '» (code ' . ($result['confirm']['code'] ?? '?') . ') foi criado no PingWin.',
            severity: 'low',
            detailPath: '/restauracao/documentos',
        );
        Log::info('[PingWin Criar Documento] Concluído', ['write_id' => $this->writeId, 'pingwin_id' => $finalId, 'code' => $result['confirm']['code'] ?? null]);
    }

    /** Insere/atualiza o espelho do NOVO documento a partir do reread (result.raw). */
    private function insertMirror(string $finalId, array $result): void
    {
        if ($finalId === '') {
            return;
        }
        $raw = $result['raw'] ?? [];
        $main = $raw['maindataset'][0] ?? [];
        $s = static fn ($v) => ($v === null || $v === '') ? null : (string) $v;

        $attrs = [
            'code'               => $s($main['code'] ?? null),
            'description'        => $s($main['description'] ?? null),
            'deleted'            => (int) ($main['deleted'] ?? 0) === 1,
            'taxscenario_id'     => $s($main['taxscenario_id'] ?? null),
            'doctype_id'         => $s($main['doctype_id'] ?? null),
            'docfiscaltype_id'   => $s($main['docfiscaltype_id'] ?? null),
            'default_paycond_id' => $s($main['default_paycond_id'] ?? null),
            'stock_signal'       => $s($main['stock_signal'] ?? null),
            'docseries_id'       => $s($main['docseries_id'] ?? null),
            'raw'                => $main,
            'synced_at'          => now(),
            'rich_synced_at'     => now(),
        ];
        // options + 14 filhas + additionalfields do reread.
        foreach (PingwinDocumentConfig::RICH_JSON_COLUMNS as $col) {
            if ($col === 'raw') {
                continue;
            }
            if ($col === 'options') {
                $attrs[$col] = ['docfiscaltype' => $raw['docfiscaltype'] ?? [], 'doctype' => $raw['doctype'] ?? [],
                                'stock_signal' => $raw['stock_signal'] ?? [], 'productgroup' => $raw['productgroup'] ?? [],
                                'taxscenario' => $raw['taxscenario'] ?? [], 'tax_round_mode' => $raw['tax_round_mode'] ?? [],
                                'contacttype' => $raw['contacttype'] ?? [], 'report' => $raw['report'] ?? [],
                                'printzone' => $raw['printzone'] ?? [], 'docseries' => $raw['docseries'] ?? []];
            } elseif ($col === 'additionalfields_maindataset') {
                $attrs[$col] = $raw['additionalfields.maindataset'] ?? null;
            } elseif ($col === 'additionalfields_storedataset') {
                $attrs[$col] = $raw['_storedataset'] ?? null;
            } elseif (array_key_exists($col, $raw)) {
                $attrs[$col] = $raw[$col];
            }
        }

        PingwinDocumentConfig::updateOrCreate(
            ['company_id' => $this->companyId, 'external_id' => $finalId],
            $attrs
        );
    }

    private function markError(PingwinDocconfigWrite $write, string $message, AlertService $alerts): void
    {
        $write->update(['status' => 'erro', 'error_message' => mb_substr($message, 0, 800), 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao criar o documento',
            message: 'Não foi possível criar o documento «' . $write->description . '» no PingWin: ' . mb_substr($message, 0, 200),
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
        Log::error('[PingWin Criar Documento] Job falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);
    }
}
