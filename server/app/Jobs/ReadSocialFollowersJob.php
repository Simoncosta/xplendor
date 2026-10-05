<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SocialConnection;
use App\Services\Social\SocialConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Seguidores automáticos (Instagram e Página de Facebook ligados nas redes sociais).
 * Diário (04:30, hora de Lisboa) para todas as empresas com a ligação ativa ou à
 * espera da aprovação da Meta; ou só para uma empresa, logo após escolher as contas.
 * Uma empresa que falhe não impede as outras.
 */
class ReadSocialFollowersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(public readonly ?int $companyId = null) {}

    public function handle(SocialConnectionService $service): void
    {
        $companyIds = $this->companyId !== null
            ? [$this->companyId]
            : SocialConnection::whereIn('status', SocialConnection::READABLE)->pluck('company_id')->all();

        $totals = ['read' => 0, 'failed' => 0];
        foreach ($companyIds as $companyId) {
            try {
                $r = $service->readCompany((int) $companyId);
                $totals['read'] += $r['read'];
                $totals['failed'] += $r['failed'];
            } catch (\Throwable $e) {
                $totals['failed']++;
                Log::warning('[Redes sociais] Leitura de seguidores falhou', ['company_id' => $companyId, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }

        if ($this->companyId === null) {
            Log::info('[Redes sociais] Leitura diária de seguidores', ['companies' => count($companyIds)] + $totals);
        }
    }
}
