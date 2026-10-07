<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\PingwinDailySale;
use App\Models\PingwinItemSale;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — F1-1 do marketing da restauração: vendas por artigo e por dia, espelho do
 * relatório "Vendas por artigo" do PingWin (documents/PINGWIN-RELATORIOS-F0.md).
 *
 * O Laravel espelha o PingWin, não é a fonte de verdade: cada leitura SUBSTITUI os
 * artigos de cada loja × dia lido. Duas proteções:
 *  · relatório sem linhas num dia que tem (ou pode ter) vendas → não se apaga nada
 *    (o servidor GrupoPIE devolve vazios ao acaso); só se apaga com o líquido diário a 0;
 *  · a soma dos artigos é conferida com o líquido diário do Resumo de Vendas
 *    (tolerância de 1%); os dias que não batem ficam marcados para voltar a ler.
 *
 * Só leituras no PingWin, em série, um pedido por bloco de até 7 dias, com espaçamento.
 * A sincronização automática só corre com o interruptor da empresa ligado.
 */
class PingwinItemSalesService
{
    /** Dias por pedido ao PingWin (o relatório parte o intervalo por dia). */
    public const DAYS_PER_CALL = 7;
    /** Intervalo máximo de uma leitura manual (o período manual da F1-2 pede até 92). */
    public const MAX_DAYS = 31;
    /** Segundos entre pedidos seguidos ao PingWin. */
    public const SPACING_SECONDS = 20;
    /** Tolerância da conferência com o líquido diário. */
    public const TOLERANCE = 0.01;

    public function __construct(private readonly PingwinService $pingwin) {}

    /** Interruptor da empresa (desligado por omissão). */
    public static function isEnabled(int $companyId): bool
    {
        return (bool) Company::whereKey($companyId)->value('pingwin_item_sales_enabled');
    }

    /**
     * Janela da noite: os 7 dias que acabam em $endDate (por omissão, ontem). Relê os
     * dias anteriores para apanhar as correções tardias no PingWin.
     *
     * @return array{0: string, 1: string}
     */
    public static function nightlyWindow(?string $endDate = null): array
    {
        $end = $endDate ? CarbonImmutable::parse($endDate) : CarbonImmutable::now()->subDay();

        return [$end->subDays(self::DAYS_PER_CALL - 1)->toDateString(), $end->toDateString()];
    }

    /**
     * Lê o intervalo em blocos de 7 dias e grava (ou só simula, com $dryRun). Devolve o
     * resumo por loja × dia, as lojas ignoradas e o peso das famílias.
     */
    public function sync(int $companyId, string $from, string $to, bool $dryRun = false, int $maxDays = self::MAX_DAYS): array
    {
        [$start, $end] = $this->validRange($from, $to, $maxDays);

        $lock = Cache::lock("pingwin-item-sales:{$companyId}", 900);
        if (! $lock->get()) {
            throw new \RuntimeException('Já está a correr uma leitura das vendas por artigo desta empresa.');
        }

        try {
            $days = [];
            $ignored = [];
            $families = [];
            $calls = 0;
            $chunkStart = $start;
            while ($chunkStart->lte($end)) {
                $chunkEnd = $chunkStart->addDays(self::DAYS_PER_CALL - 1)->min($end);
                if ($calls > 0) {
                    Sleep::for(self::SPACING_SECONDS)->seconds();
                }
                $rows = $this->pingwin->fetchItemSales($companyId, $chunkStart->toDateString(), $chunkEnd->toDateString());
                $calls++;

                $applied = $this->apply($companyId, $chunkStart->toDateString(), $chunkEnd->toDateString(), $rows, $dryRun);
                array_push($days, ...$applied['days']);
                $ignored = array_values(array_unique([...$ignored, ...$applied['ignored_stores']]));
                foreach ($applied['families'] as $path => $cents) {
                    $families[$path] = ($families[$path] ?? 0) + $cents;
                }
                $chunkStart = $chunkEnd->addDay();
            }
        } finally {
            $lock->release();
        }

        arsort($families);
        $summary = [
            'company_id' => $companyId,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'dry_run' => $dryRun,
            'calls' => $calls,
            'days' => $days,
            'ignored_stores' => $ignored,
            'families' => $families,
        ];
        Log::info('[PingWin Vendas por artigo] leitura', [
            'company_id' => $companyId, 'from' => $summary['from'], 'to' => $summary['to'], 'dry_run' => $dryRun,
            'calls' => $calls, 'estados' => array_count_values(array_column($days, 'status')),
        ]);

        return $summary;
    }

    /**
     * Aplica as linhas de um bloco: por loja ativa × dia do bloco, substitui os artigos
     * (ou protege o dia vazio) e regista o estado da conferência. Com $dryRun não escreve.
     */
    public function apply(int $companyId, string $from, string $to, array $rows, bool $dryRun = false): array
    {
        $locations = PingwinLocation::where('company_id', $companyId)->where('is_active', true)->orderBy('id')->get();
        $byStore = $locations->keyBy(fn (PingwinLocation $l) => trim((string) $l->winrest_store_id));

        // Agrupa por loja × dia × artigo (soma se o relatório repetir a chave).
        $grouped = [];
        $ignored = [];
        foreach ($rows as $row) {
            $date = (string) ($row['date'] ?? '');
            $location = $byStore->get(trim((string) ($row['store_id'] ?? '')));
            if (! $location) {
                $ignored[] = (string) ($row['store_id'] ?? '');
                continue;
            }
            if ($date < $from || $date > $to) {
                continue;
            }
            $product = mb_substr(trim((string) ($row['product_id'] ?? '')), 0, 32);
            if ($product === '') {
                continue;
            }
            $item = &$grouped[$location->id][$date][$product];
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
        if ($ignored !== []) {
            Log::warning('[PingWin Vendas por artigo] linhas de lojas não cadastradas (ignoradas)', [
                'company_id' => $companyId, 'lojas' => array_values(array_unique($ignored)),
            ]);
        }

        $dailyNet = [];
        PingwinDailySale::where('company_id', $companyId)
            ->whereIn('location_id', $locations->pluck('id'))
            ->whereRaw('DATE(business_date) BETWEEN ? AND ?', [$from, $to])
            ->get(['location_id', 'business_date', 'net_cents'])
            ->each(function ($s) use (&$dailyNet) {
                $dailyNet[$s->location_id][substr((string) $s->business_date, 0, 10)] = (int) $s->net_cents;
            });

        $now = now();
        $days = [];
        $families = [];
        foreach ($locations as $location) {
            // F1-2: antes do primeiro mês com vendas detetado, a loja não existia no
            // PingWin; esses dias não se leem nem se marcam.
            $firstMonth = $location->sales_first_month?->toDateString();
            foreach (CarbonPeriod::create($from, $to) as $day) {
                $date = $day->toDateString();
                if ($firstMonth !== null && $date < $firstMonth) {
                    continue;
                }
                $items = $grouped[$location->id][$date] ?? [];
                $daily = $dailyNet[$location->id][$date] ?? null;
                $itemsNet = array_sum(array_column($items, 'net_cents'));
                $status = $this->status($items, $itemsNet, $daily);

                if (! $dryRun) {
                    DB::transaction(function () use ($companyId, $location, $date, $items, $status, $itemsNet, $daily, $now) {
                        $replace = $items !== [] || $status === PingwinItemSalesDay::STATUS_EMPTY;
                        if ($replace) {
                            PingwinItemSale::where('location_id', $location->id)->where('business_date', $date)->delete();
                        }
                        foreach (array_chunk(array_keys($items), 500) as $chunk) {
                            PingwinItemSale::insert(array_map(fn ($product) => $items[$product] + [
                                'company_id' => $companyId, 'location_id' => $location->id, 'business_date' => $date,
                                'product_pingwin_id' => (string) $product, 'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                            ], $chunk));
                        }
                        $record = PingwinItemSalesDay::firstOrNew(['location_id' => $location->id, 'business_date' => $date]);
                        $record->fill(['company_id' => $companyId, 'status' => $status, 'rows_count' => count($items),
                            'items_net_cents' => $itemsNet, 'daily_net_cents' => $daily, 'synced_at' => $now]);
                        $record->reads_count = $record->exists ? $record->reads_count + 1 : 1;
                        $record->save();
                    });
                }

                foreach ($items as $item) {
                    $path = $item['family_path'] ?? 'Sem família';
                    $families[$path] = ($families[$path] ?? 0) + $item['net_cents'];
                }
                $days[] = [
                    'location_id' => $location->id,
                    'location' => $location->display_name ?: $location->winrest_name ?: (string) $location->winrest_store_id,
                    'date' => $date,
                    'rows' => count($items),
                    'items_net_cents' => $itemsNet,
                    'daily_net_cents' => $daily,
                    'status' => $status,
                ];
            }
        }

        return ['days' => $days, 'ignored_stores' => array_values(array_unique($ignored)), 'families' => $families];
    }

    /** Estado de uma loja × dia (ver as constantes de PingwinItemSalesDay). */
    private function status(array $items, int $itemsNet, ?int $daily): string
    {
        if ($items === []) {
            // Só um líquido diário a zero confirma que o dia não teve vendas.
            return $daily === 0 ? PingwinItemSalesDay::STATUS_EMPTY : PingwinItemSalesDay::STATUS_EMPTY_PROTECTED;
        }
        if ($daily === null) {
            return PingwinItemSalesDay::STATUS_UNVERIFIED;
        }
        $tolerance = max(1, (int) round(abs($daily) * self::TOLERANCE));

        return abs($itemsNet - $daily) <= $tolerance ? PingwinItemSalesDay::STATUS_OK : PingwinItemSalesDay::STATUS_MISMATCH;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function validRange(string $from, string $to, int $maxDays): array
    {
        $valid = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && CarbonImmutable::createFromFormat('Y-m-d', $d)?->toDateString() === $d;
        if (! $valid($from) || ! $valid($to)) {
            throw ValidationException::withMessages(['period' => ['Datas inválidas (AAAA-MM-DD).']]);
        }
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['period' => ['A data final é anterior à inicial.']]);
        }
        if ($start->diffInDays($end) + 1 > $maxDays) {
            throw ValidationException::withMessages(['period' => ["No máximo {$maxDays} dias de cada vez."]]);
        }
        if ($end->gte(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['period' => ['Só dias já fechados (até ontem).']]);
        }

        return [$start, $end];
    }

    private function cents($value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function limit($value, int $max): ?string
    {
        $s = trim((string) ($value ?? ''));

        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
