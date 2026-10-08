<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PingwinHourlySale;
use App\Models\PingwinHourlySalesDay;
use App\Models\PingwinLocation;

/**
 * XPLENDOR — F2 do marketing da restauração (períodos fracos): vendas por loja × dia × hora,
 * espelho do relatório "Vendas por hora" do PingWin (documents/PINGWIN-RELATORIOS-F0.md §4).
 * A soma das horas é conferida com o líquido diário, como nas vendas por artigo
 * (PingwinDailyMirrorService). Atrás do mesmo interruptor por empresa.
 */
class PingwinHourlySalesService extends PingwinDailyMirrorService
{
    protected string $dayNetColumn = 'hours_net_cents';
    protected string $summaryKey = 'hours';

    protected function label(): string
    {
        return '[PingWin Vendas por hora]';
    }

    protected function lockName(): string
    {
        return 'pingwin-hourly-sales';
    }

    protected function fetch(int $companyId, string $from, string $to): array
    {
        return $this->pingwin->fetchHourlySales($companyId, $from, $to);
    }

    /** Agrupa por hora (0 a 23); ignora horas fora do intervalo. */
    protected function groupRow(array $row, array &$group): void
    {
        $hour = $row['hour'] ?? null;
        if (! is_numeric($hour) || (int) $hour < 0 || (int) $hour > 23) {
            return;
        }
        $hour = (int) $hour;
        $group[$hour] ??= ['net_cents' => 0];
        $group[$hour]['net_cents'] += $this->cents($row['net'] ?? 0);
    }

    protected function replaceDay(int $companyId, PingwinLocation $location, string $date, array $items, $now, bool $clear): void
    {
        if ($clear) {
            PingwinHourlySale::where('location_id', $location->id)->where('business_date', $date)->delete();
        }
        if ($items !== []) {
            PingwinHourlySale::insert(array_map(fn ($hour) => [
                'company_id' => $companyId, 'location_id' => $location->id, 'business_date' => $date, 'hour' => (int) $hour,
                'net_cents' => $items[$hour]['net_cents'], 'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], array_keys($items)));
        }
    }

    protected function dayModel(): string
    {
        return PingwinHourlySalesDay::class;
    }

    /** Valor por hora (sem IVA), somado em todas as lojas e dias lidos. */
    protected function summarize(array $items, array &$summary): void
    {
        foreach ($items as $hour => $item) {
            $key = sprintf('%02dh', $hour);
            $summary[$key] = ($summary[$key] ?? 0) + $item['net_cents'];
        }
    }
}
