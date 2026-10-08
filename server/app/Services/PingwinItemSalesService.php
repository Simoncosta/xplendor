<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PingwinItemSale;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;

/**
 * XPLENDOR — F1-1 do marketing da restauração: vendas por artigo e por dia, espelho do
 * relatório "Vendas por artigo" do PingWin (documents/PINGWIN-RELATORIOS-F0.md). A lógica
 * do espelho e da conferência com o líquido diário está em PingwinDailyMirrorService.
 */
class PingwinItemSalesService extends PingwinDailyMirrorService
{
    protected string $dayNetColumn = 'items_net_cents';
    protected string $summaryKey = 'families';

    protected function label(): string
    {
        return '[PingWin Vendas por artigo]';
    }

    protected function lockName(): string
    {
        return 'pingwin-item-sales';
    }

    protected function fetch(int $companyId, string $from, string $to): array
    {
        return $this->pingwin->fetchItemSales($companyId, $from, $to);
    }

    /** Agrupa por artigo (soma se o relatório repetir a chave). */
    protected function groupRow(array $row, array &$group): void
    {
        $product = mb_substr(trim((string) ($row['product_id'] ?? '')), 0, 32);
        if ($product === '') {
            return;
        }
        $item = &$group[$product];
        $item ??= [
            'product_code' => $this->limit($row['product_code'] ?? null, 64),
            'product_name' => $this->limit($row['product_name'] ?? null, 255),
            'family_pingwin_id' => $this->limit($row['family_id'] ?? null, 32),
            'family_path' => $this->limit($row['family_path'] ?? null, 255),
            'quantity' => 0.0, 'net_cents' => 0, 'tax_cents' => 0, 'gross_cents' => 0,
        ];
        $item['quantity'] += (float) ($row['qty'] ?? 0);
        $item['net_cents'] += $this->cents($row['net'] ?? 0);
        $item['tax_cents'] += $this->cents($row['tax'] ?? 0);
        $item['gross_cents'] += $this->cents($row['gross'] ?? 0);
        unset($item);
    }

    protected function replaceDay(int $companyId, PingwinLocation $location, string $date, array $items, $now, bool $clear): void
    {
        if ($clear) {
            PingwinItemSale::where('location_id', $location->id)->where('business_date', $date)->delete();
        }
        foreach (array_chunk(array_keys($items), 500) as $chunk) {
            PingwinItemSale::insert(array_map(fn ($product) => $items[$product] + [
                'company_id' => $companyId, 'location_id' => $location->id, 'business_date' => $date,
                'product_pingwin_id' => (string) $product, 'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
    }

    protected function dayModel(): string
    {
        return PingwinItemSalesDay::class;
    }

    /** Peso de cada família (sem IVA). */
    protected function summarize(array $items, array &$summary): void
    {
        foreach ($items as $item) {
            $path = $item['family_path'] ?? 'Sem família';
            $summary[$path] = ($summary[$path] ?? 0) + $item['net_cents'];
        }
    }

    private function limit($value, int $max): ?string
    {
        $s = trim((string) ($value ?? ''));

        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
