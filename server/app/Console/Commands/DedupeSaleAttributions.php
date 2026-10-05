<?php

namespace App\Console\Commands;

use App\Support\SaleAttributionUniqueness;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Limpa as atribuições de vendas DUPLICADAS (várias para a mesma viatura, por a
 * venda ter sido marcada mais do que uma vez com horas diferentes).
 *
 * SIMULAÇÃO por omissão: não grava nada; mostra e guarda um relatório (CSV) com o
 * que ficaria e o que sairia. Só grava com --write, depois de ver o relatório:
 *
 *   php artisan sales:dedupe-attributions               # simulação
 *   php artisan sales:dedupe-attributions --company=3
 *   php artisan sales:dedupe-attributions --write       # limpa e cria a chave única
 *
 * Qual fica, por viatura:
 *   1. com venda registada (car_sales): a atribuição com a data mais próxima da
 *      venda registada; fica com essa data e com a ligação car_sale_id;
 *   2. sem venda registada: a mais próxima da data de venda da viatura (cars.sold_at);
 *   3. empate: a de maior confiança e, depois, a mais recente.
 * As restantes apagam-se. Com --write, no fim cria-se a chave única por viatura.
 */
class DedupeSaleAttributions extends Command
{
    protected $signature = 'sales:dedupe-attributions
                            {--company= : Só esta empresa}
                            {--write : Grava (sem isto, é só simulação)}';

    protected $description = 'Limpa atribuições de vendas duplicadas (simulação por omissão)';

    public function handle(): int
    {
        $write = (bool) $this->option('write');

        $groups = DB::table('car_sale_attributions')
            ->when($this->option('company'), fn ($q) => $q->where('company_id', (int) $this->option('company')))
            ->select('company_id', 'car_id')
            ->groupBy('company_id', 'car_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('company_id')->orderBy('car_id')
            ->get();

        $report = [];
        $deleted = 0;

        foreach ($groups as $g) {
            $rows = DB::table('car_sale_attributions')->where('company_id', $g->company_id)->where('car_id', $g->car_id)->orderBy('id')->get();
            $sale = DB::table('car_sales')->where('car_id', $g->car_id)->first(['id', 'sold_at']);
            $carSoldAt = DB::table('cars')->where('id', $g->car_id)->value('sold_at');
            $reference = $sale?->sold_at ?? $carSoldAt;

            $keep = $rows->sortBy([
                fn ($a, $b) => $this->distance($a->sold_at, $reference) <=> $this->distance($b->sold_at, $reference),
                fn ($a, $b) => (int) $b->confidence_score <=> (int) $a->confidence_score,
                fn ($a, $b) => $b->id <=> $a->id,
            ])->first();
            $drop = $rows->where('id', '!=', $keep->id)->values();

            $report[] = [
                'company_id' => $g->company_id,
                'car_id' => $g->car_id,
                'registered_sale_at' => $sale?->sold_at,
                'car_sold_at' => $carSoldAt,
                'keep_id' => $keep->id,
                'keep_sold_at' => $keep->sold_at,
                'keep_match' => $keep->match_type,
                'keep_confidence' => $keep->confidence_score,
                'keep_new_sold_at' => $sale?->sold_at ?? $keep->sold_at,
                'drop_ids' => $drop->pluck('id')->implode(' '),
                'drop_sold_at' => $drop->pluck('sold_at')->implode(' | '),
                'drop_match' => $drop->pluck('match_type')->implode(' '),
            ];

            if ($write) {
                DB::transaction(function () use ($drop, $keep, $sale, &$deleted) {
                    $deleted += DB::table('car_sale_attributions')->whereIn('id', $drop->pluck('id'))->delete();
                    // A data vai SEMPRE explícita: em MariaDB sold_at tem ON UPDATE
                    // CURRENT_TIMESTAMP e uma atualização sem ela reescreveria a data da venda.
                    DB::table('car_sale_attributions')->where('id', $keep->id)->update([
                        'car_sale_id' => $sale?->id,
                        'sold_at' => $sale?->sold_at ?? $keep->sold_at,
                        'updated_at' => now(),
                    ]);
                });
            }
        }

        if ($report !== []) {
            $this->table(['empresa', 'viatura', 'venda registada', 'fica (id · data · tipo)', 'sai (id · data)'], array_map(fn ($r) => [
                $r['company_id'], $r['car_id'], $r['registered_sale_at'] ?? '(sem venda registada)',
                "{$r['keep_id']} · {$r['keep_sold_at']} · {$r['keep_match']}",
                "{$r['drop_ids']} · {$r['drop_sold_at']}",
            ], $report));
        }

        $path = 'reports/dedupe_sale_attributions_' . now()->format('Ymd_His') . ($write ? '_gravado' : '_simulacao') . '.csv';
        Storage::disk('local')->put($path, $this->csv($report));
        $shown = ltrim(str_replace(base_path(), '', Storage::disk('local')->path($path)), '/');

        $index = '';
        if ($write) {
            $index = match (SaleAttributionUniqueness::ensure()) {
                'created' => ' Chave única por viatura criada.',
                'exists' => ' A chave única por viatura já existia.',
                default => ' Ainda há duplicados: a chave única não foi criada.',
            };
        }

        $this->info(sprintf(
            '%s: %d viaturas com atribuições duplicadas · %d atribuições %s.%s Relatório: %s',
            $write ? 'GRAVADO' : 'SIMULAÇÃO (nada foi gravado)',
            count($report),
            $write ? $deleted : array_sum(array_map(fn ($r) => count(array_filter(explode(' ', $r['drop_ids']))), $report)),
            $write ? 'apagadas' : 'sairiam',
            $index,
            $shown
        ));

        return self::SUCCESS;
    }

    /** Distância em segundos à data de referência (sem referência: igual para todas). */
    private function distance(?string $at, ?string $reference): int
    {
        if ($at === null || $reference === null) {
            return 0;
        }

        return (int) round(abs(Carbon::parse($at)->diffInSeconds(Carbon::parse($reference), false)));
    }

    private function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_keys($rows[0] ?? ['company_id' => 0, 'car_id' => 0]));
        foreach ($rows as $r) {
            fputcsv($out, array_values($r));
        }
        rewind($out);

        return stream_get_contents($out);
    }
}
