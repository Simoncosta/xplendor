<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\PingwinItemHistoryService;
use App\Services\PingwinItemSalesService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — F1-2, MANUAL: catálogo completo do PingWin (leitura que não para numa página
 * curta e, se faltarem artigos vendidos, família a família). Mostra o diagnóstico da
 * paginação e a cobertura dos artigos vendidos nos últimos 90 dias. Só leitura no
 * PingWin; exige o interruptor da empresa ligado.
 *
 * ⚠️ Corre no worker (docker socket):
 *   docker exec xplendor-worker php artisan pingwin:catalog-complete 5
 */
class PingwinCatalogCompleteCommand extends Command
{
    protected $signature = 'pingwin:catalog-complete {company : ID da empresa (ex.: 5 = Yuko)}';

    protected $description = 'Lê o catálogo completo do PingWin e mostra a cobertura dos artigos vendidos.';

    public function handle(PingwinItemHistoryService $history): int
    {
        $companyId = (int) $this->argument('company');
        $company = Company::find($companyId);
        if (! $company) {
            $this->error("Empresa {$companyId} não existe.");

            return self::FAILURE;
        }
        if (! PingwinItemSalesService::isEnabled($companyId)) {
            $this->error("O interruptor está desligado: ligue-o com pingwin:item-sales-switch {$companyId} on.");

            return self::FAILURE;
        }

        $before = count(PingwinItemHistoryService::missingSoldProductIds($companyId));
        $this->line("Empresa: {$company->fiscal_name} ({$companyId})");
        $this->line("Artigos vendidos (90 dias) fora do catálogo, antes: {$before}");

        try {
            $r = $history->syncCatalogComplete($companyId);
        } catch (\Throwable $e) {
            $this->error('Falhou: ' . $e->getMessage());

            return self::FAILURE;
        }

        $d = $r['diagnostics'] ?? [];
        $this->line("Artigos no catálogo: {$r['count']}");
        $this->line('Páginas: ' . count($d['pages'] ?? []) . '; total anunciado: ' . ($d['announced_total'] ?? 'nenhum')
            . '; parou por: ' . ($d['stopped'] ?? '?'));
        foreach ($d['pages'] ?? [] as $p) {
            $this->line("  {$p['range']}: {$p['items']} itens ({$p['new']} novos)");
        }
        if ($r['by_family']) {
            $bf = $d['by_family'] ?? [];
            $this->line('Leitura família a família: ' . ($bf['families'] ?? '?') . ' famílias, ' . ($bf['added'] ?? '?') . ' artigos acrescentados.');
        }
        $coverage = $r['sold'] > 0 ? round(($r['sold'] - $r['missing_after']) / $r['sold'] * 100, 1) : null;
        $this->line("Artigos vendidos (90 dias): {$r['sold']}; fora do catálogo depois: {$r['missing_after']}"
            . ($coverage !== null ? "; cobertura {$coverage}%" : ''));
        if ($r['missing_sample'] !== []) {
            $this->warn('Exemplos em falta (ID PingWin): ' . implode(', ', $r['missing_sample']));
        }
        $r['missing_after'] === 0 ? $this->info('Catálogo completo.') : $this->warn('Ainda faltam artigos vendidos no catálogo.');

        return self::SUCCESS;
    }
}
