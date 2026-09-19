<?php

namespace App\Jobs;

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
 * XPLENDOR — Validação da ligação PingWin em FILA. A validação síncrona falha no
 * php-fpm (sem docker socket); aqui corre no worker (que TEM o socket). Faz
 * login → LOGOUT garantido (ciclo já existente), atualiza o estado da integração
 * e NOTIFICA no sino com o resultado — sucesso, ou o MOTIVO REAL da falha.
 *
 * ⚠️ Serializado por empresa (mesma chave "pingwin-sync:{companyId}" das buscas):
 * nunca duas operações PingWin da mesma empresa em simultâneo.
 */
class ValidatePingwinConnectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 240;

    public function __construct(public int $companyId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->expireAfter(600),
        ];
    }

    public function handle(PingwinService $pingwin, AlertService $alerts): void
    {
        Log::info('[PingWin Validate] Job iniciado', ['company_id' => $this->companyId]);

        // validateStored atualiza o estado (active/error) e devolve o motivo real.
        $result = $pingwin->validateStored($this->companyId);

        if ($result['ok'] ?? false) {
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'opportunity',
                title: 'Ligação PingWin validada',
                message: 'A ligação ao PingWin foi validada com sucesso (login + logout).',
                severity: 'low',
                detailPath: '/restauracao',
            );
            Log::info('[PingWin Validate] Sucesso', ['company_id' => $this->companyId]);
            return;
        }

        $reason = mb_substr((string) ($result['error'] ?? 'motivo desconhecido'), 0, 300);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao ligar ao PingWin',
            message: "Não foi possível validar a ligação. Motivo: {$reason}",
            severity: 'high',
            detailPath: '/restauracao',
        );
        Log::warning('[PingWin Validate] Falha', ['company_id' => $this->companyId, 'reason' => $reason]);
    }

    /** Rede de segurança: se o próprio job rebentar, notifica na mesma. */
    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin Validate] Job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);

        app(AlertService::class)->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao ligar ao PingWin',
            message: 'Não foi possível validar a ligação ao PingWin. Tenta novamente.',
            severity: 'high',
            detailPath: '/restauracao',
        );
    }
}
