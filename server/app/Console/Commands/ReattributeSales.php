<?php

namespace App\Console\Commands;

use App\Models\Car;
use App\Models\CarSaleAttribution;
use App\Services\CampaignToSaleAttributionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Reatribui vendas antigas com a regra nova (anúncios com tag, cross_car, recurso
 * pela tag, mapeamento só para campanhas sem tag).
 *
 * SIMULAÇÃO por omissão: não grava nada; mostra e guarda um relatório (CSV em
 * storage/app/reports) do que mudaria. Só grava com --write, depois de ver o
 * relatório:
 *
 *   php artisan meta:reattribute-sales --since=2025-09-01                 # simulação
 *   php artisan meta:reattribute-sales --since=2025-09-01 --company=12
 *   php artisan meta:reattribute-sales --since=2025-09-01 --write         # grava
 */
class ReattributeSales extends Command
{
    protected $signature = 'meta:reattribute-sales
                            {--since= : Vendas desde esta data (AAAA-MM-DD); por omissão, há 13 meses}
                            {--company= : Só esta empresa}
                            {--write : Grava (sem isto, é só simulação)}';

    protected $description = 'Reatribui vendas antigas a anúncios com tag (simulação por omissão)';

    private const FIELDS = ['attributed_campaign_id', 'attributed_adset_id', 'attributed_ad_id', 'match_type'];

    public function handle(CampaignToSaleAttributionService $service): int
    {
        $since = $this->option('since') ? Carbon::parse($this->option('since'))->startOfDay() : now()->subMonths(13)->startOfMonth();
        $write = (bool) $this->option('write');

        $cars = Car::query()
            ->where('status', 'sold')
            ->whereNotNull('sold_at')
            ->where('sold_at', '>=', $since)
            ->when($this->option('company'), fn ($q) => $q->where('company_id', (int) $this->option('company')))
            ->orderBy('company_id')
            ->orderBy('sold_at')
            ->get();

        $report = [];
        $counts = ['unchanged' => 0, 'changed' => 0, 'new' => 0];

        foreach ($cars as $car) {
            $soldAt = Carbon::parse($car->sold_at);
            $old = CarSaleAttribution::where('company_id', $car->company_id)->where('car_id', $car->id)
                ->where('sold_at', $soldAt)->first();
            $new = $service->attributeSale($car, ['sold_at' => $soldAt]);

            $newValues = [
                'attributed_campaign_id' => $new['campaign_id'],
                'attributed_adset_id' => $new['adset_id'],
                'attributed_ad_id' => $new['ad_id'],
                'match_type' => $new['match_type'],
            ];
            $oldValues = $old ? $old->only(self::FIELDS) : array_fill_keys(self::FIELDS, null);

            $state = ! $old ? 'new' : ($this->same($oldValues, $newValues) && (int) $old->confidence_score === (int) $new['confidence_score'] ? 'unchanged' : 'changed');
            $counts[$state]++;

            if ($state !== 'unchanged') {
                $report[] = [
                    'company_id' => $car->company_id,
                    'car_id' => $car->id,
                    'sold_at' => $soldAt->toDateString(),
                    'state' => $state,
                    'old_match' => $oldValues['match_type'],
                    'old_campaign' => $oldValues['attributed_campaign_id'],
                    'old_ad' => $oldValues['attributed_ad_id'],
                    'old_confidence' => $old?->confidence_score,
                    'new_match' => $new['match_type'],
                    'new_campaign' => $new['campaign_id'],
                    'new_ad' => $new['ad_id'],
                    'new_confidence' => $new['confidence_score'],
                    'new_reason' => $new['confidence_reason'],
                ];
            }

            if ($write && $state !== 'unchanged') {
                $service->recordSaleAttribution($car, [
                    'sold_at' => $soldAt,
                    'sale_price' => $old?->sale_price ?? DB::table('car_sales')->where('car_id', $car->id)->value('sale_price'),
                ]);
            }
        }

        if ($report !== []) {
            $this->table(['empresa', 'viatura', 'venda', 'estado', 'antes', 'depois', 'confiança'], array_map(fn ($r) => [
                $r['company_id'], $r['car_id'], $r['sold_at'], $r['state'],
                trim(($r['old_match'] ?? '-') . ' ' . ($r['old_ad'] ?? $r['old_campaign'] ?? '')),
                trim($r['new_match'] . ' ' . ($r['new_ad'] ?? $r['new_campaign'] ?? '')),
                ($r['old_confidence'] ?? '-') . ' → ' . $r['new_confidence'],
            ], $report));
        }

        $path = 'reports/reattribute_sales_' . now()->format('Ymd_His') . ($write ? '_gravado' : '_simulacao') . '.csv';
        Storage::disk('local')->put($path, $this->csv($report));

        $this->info(sprintf(
            '%s: %d vendas · %d sem alteração · %d mudariam · %d novas. Relatório: storage/app/%s',
            $write ? 'GRAVADO' : 'SIMULAÇÃO (nada foi gravado)',
            $cars->count(), $counts['unchanged'], $counts['changed'], $counts['new'], $path
        ));

        return self::SUCCESS;
    }

    private function same(array $a, array $b): bool
    {
        foreach (self::FIELDS as $f) {
            if ((string) ($a[$f] ?? '') !== (string) ($b[$f] ?? '')) {
                return false;
            }
        }

        return true;
    }

    private function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_keys($rows[0] ?? ['company_id' => 0, 'car_id' => 0, 'sold_at' => 0, 'state' => 0]));
        foreach ($rows as $r) {
            fputcsv($out, array_values($r));
        }
        rewind($out);

        return stream_get_contents($out);
    }
}
