<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PingwinPaymentCondition;
use App\Services\PingwinService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — Disparo MANUAL do sync de Condições de Pagamento (Fatia 1, só leitura).
 * Síncrono (chama o PingwinService diretamente), para testar/operar a partir da CLI.
 *
 * ⚠️ O invoke() faz `docker exec` ao container do scraper → este comando TEM de
 * correr no container QUE TEM o socket Docker (o worker):
 *   docker exec xplendor-worker php artisan pingwin:sync-paycond 5
 */
class PingwinSyncPaymentConditionsCommand extends Command
{
    protected $signature = 'pingwin:sync-paycond {company : ID da empresa (ex.: 5 = Yuko)}';

    protected $description = 'Sincroniza (leitura) as Condições de Pagamento do PingWin para o espelho da empresa.';

    public function handle(PingwinService $pingwin): int
    {
        $companyId = (int) $this->argument('company');
        $this->info("A sincronizar condições de pagamento PingWin da empresa {$companyId}…");

        try {
            $count = $pingwin->syncPaymentConditions($companyId);
        } catch (\Throwable $e) {
            $this->error('Falhou: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("OK — {$count} condição(ões) guardada(s).");

        $rows = PingwinPaymentCondition::where('company_id', $companyId)
            ->orderBy('code')->orderBy('description')
            ->get(['pingwin_id', 'code', 'description', 'discount', 'days', 'is_active', 'tbdocs']);

        $this->table(
            ['pingwin_id', 'code', 'description', 'discount %', 'days', 'ativo', '#tbdocs'],
            $rows->map(fn ($r) => [
                $r->pingwin_id,
                $r->code,
                $r->description,
                $r->discount,
                $r->days,
                $r->is_active ? 'sim' : 'não',
                is_array($r->tbdocs) ? count($r->tbdocs) : 0,
            ])->all()
        );

        return self::SUCCESS;
    }
}
