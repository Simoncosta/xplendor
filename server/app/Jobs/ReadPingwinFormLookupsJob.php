<?php

namespace App\Jobs;

use App\Services\PingwinService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — LEITURA do form de criação de artigo (form-lookups), no WORKER (root).
 * ⚠️ Opção 1: quem faz docker exec é SEMPRE o worker; o php-fpm (www-data) nunca toca
 * no socket. Leitura idempotente → PODE ter retry (tries=3), ao contrário das escritas.
 * O resultado vai para Redis (token+TTL); a UI faz polling e consome.
 */
class ReadPingwinFormLookupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;       // leitura idempotente → retry seguro (resolve transitórios)
    public int $backoff = 3;
    public int $timeout = 120;

    public function __construct(public int $companyId, public string $token) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(10)->expireAfter(300)];
    }

    public function handle(PingwinService $pingwin): void
    {
        $form = $pingwin->productFormLookups($this->companyId); // pode lançar → retry
        Cache::put(
            PingwinService::readKey($this->companyId, $this->token),
            ['status' => 'ready', 'result' => ['form' => $form]],
            now()->addSeconds(120) // rede de segurança; a UI apaga ao consumir
        );
    }

    /** Só após esgotar as tentativas: guarda o erro HUMANO para a UI. */
    public function failed(\Throwable $e): void
    {
        Cache::put(
            PingwinService::readKey($this->companyId, $this->token),
            ['status' => 'error', 'error_message' => PingwinService::humanError($e->getMessage())],
            now()->addSeconds(120)
        );
        Log::warning('[PingWin Ler Form] Falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
    }
}
