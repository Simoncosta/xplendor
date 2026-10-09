<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PingwinSupplier;
use App\Services\SupplierCcService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — Conta corrente de fornecedor (S1, SÓ LEITURA): sync MANUAL e síncrona,
 * de um fornecedor ou de todos (em lotes de 40 por docker exec).
 *
 * ⚠️ O invoke() faz `docker exec` ao container do scraper → corre no WORKER:
 *   docker exec xplendor-worker php artisan pingwin:sync-supplier-cc 5
 *   docker exec xplendor-worker php artisan pingwin:sync-supplier-cc 5 --supplier=44
 */
class PingwinSyncSupplierCcCommand extends Command
{
    protected $signature = 'pingwin:sync-supplier-cc
        {company : ID da empresa (ex.: 5 = Yuko)}
        {--supplier= : Só este fornecedor — código no PingWin (ex.: 44) ou pingwin_id}';

    protected $description = 'Sincroniza (leitura) a conta corrente dos fornecedores PingWin da empresa.';

    public function handle(SupplierCcService $cc): int
    {
        $companyId = (int) $this->argument('company');
        $supplierIds = null;

        if (($ref = $this->option('supplier')) !== null) {
            $supplier = PingwinSupplier::where('company_id', $companyId)
                ->where(fn ($q) => $q->where('pingwin_id', $ref)->orWhere('code', $ref))
                ->first();
            if (! $supplier) {
                $this->error("Fornecedor '{$ref}' não encontrado (código ou pingwin_id) na empresa {$companyId}.");

                return self::FAILURE;
            }
            $supplierIds = [$supplier->id];
            $this->info("Fornecedor: {$supplier->code} — {$supplier->name} (pingwin_id {$supplier->pingwin_id})");
        }

        try {
            $summary = $cc->sync($companyId, $supplierIds);
        } catch (\Throwable $e) {
            $this->error('Falhou: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Fornecedores: %d · ok: %d · falharam: %d · documentos: %d · reconciliados: %d/%d · %.1fs',
            $summary['suppliers'], $summary['ok'], $summary['failed'], $summary['documents'],
            $summary['reconciled'], $summary['ok'], $summary['seconds']
        ));
        foreach ($summary['errors'] as $sid => $err) {
            $this->warn("  fornecedor {$sid}: {$err}");
        }

        if ($supplierIds !== null) {
            $b = $cc->balances($companyId, $supplierIds[0]);
            $eur = fn (?int $c) => $c === null ? '—' : number_format($c / 100, 2, ',', '.');
            $this->table(
                ['Saldo PingWin', 'Saldo real', 'Diferença', 'Vencido', 'Reconciliado', 'Estado'],
                [[$eur($b['pingwin_balance_cents']), $eur($b['real_balance_cents']), $eur($b['difference_cents']),
                  $eur($b['overdue_cents']), $b['reconciled'] ? 'sim' : 'não', $b['sync_status']]]
            );
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
