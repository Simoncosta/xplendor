<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\PingwinDailySale;
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
 * XPLENDOR — espelho diário de um relatório do PingWin conferido com o líquido diário do
 * Resumo de Vendas. Partilhado pelas vendas por artigo (F1) e pelas vendas por hora (F2).
 *
 * O Laravel espelha o PingWin, não é a fonte de verdade: cada leitura SUBSTITUI as linhas
 * de cada loja × dia lido. Proteções:
 *  · relatório sem linhas num dia que tem (ou pode ter) vendas → não se apaga nada (o
 *    servidor GrupoPIE devolve vazios ao acaso); só se apaga com o líquido diário a 0 ou
 *    antes do primeiro dia com vendas da loja;
 *  · a soma das linhas é conferida com o líquido diário (tolerância de 1%); um dia que não
 *    bate relê primeiro o Resumo de Vendas desse dia e só fica marcado se continuar a não
 *    bater.
 *
 * Só leituras no PingWin, em série, um pedido por bloco de até 7 dias, com espaçamento.
 * A sincronização automática só corre com o interruptor da empresa ligado.
 */
abstract class PingwinDailyMirrorService
{
    /** Dias por pedido ao PingWin (os relatórios partem o intervalo por dia). */
    public const DAYS_PER_CALL = 7;
    /** Intervalo máximo de uma leitura manual (o período manual pede até 92). */
    public const MAX_DAYS = 31;
    /** Segundos entre pedidos seguidos ao PingWin. */
    public const SPACING_SECONDS = 20;
    /** Tolerância da conferência com o líquido diário. */
    public const TOLERANCE = 0.01;

    /** Coluna, no registo do dia, com a soma das linhas. */
    protected string $dayNetColumn = 'items_net_cents';
    /** Nome do resumo devolvido por sync() (famílias, horas). */
    protected string $summaryKey = 'summary';

    public function __construct(protected readonly PingwinService $pingwin) {}

    /** Prefixo dos registos ("[PingWin Vendas por artigo]"). */
    abstract protected function label(): string;

    /** Nome do bloqueio (uma leitura de cada vez por empresa). */
    abstract protected function lockName(): string;

    /** Pede as linhas de um bloco ao PingWin. */
    abstract protected function fetch(int $companyId, string $from, string $to): array;

    /** Junta uma linha do relatório ao grupo da sua loja × dia (chave própria de cada relatório). */
    abstract protected function groupRow(array $row, array &$group): void;

    /** Substitui as linhas de uma loja × dia (dentro de uma transação). */
    abstract protected function replaceDay(int $companyId, PingwinLocation $location, string $date, array $items, $now, bool $clear): void;

    /** Modelo do registo de cada loja × dia (com os estados de PingwinItemSalesDay). */
    abstract protected function dayModel(): string;

    /** Acrescenta as linhas de um dia ao resumo devolvido por sync(). */
    protected function summarize(array $items, array &$summary): void {}

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
     * resumo por loja × dia, as lojas ignoradas e o resumo próprio do relatório.
     */
    public function sync(int $companyId, string $from, string $to, bool $dryRun = false, int $maxDays = self::MAX_DAYS): array
    {
        [$start, $end] = $this->validRange($from, $to, $maxDays);

        $lock = Cache::lock("{$this->lockName()}:{$companyId}", 900);
        if (! $lock->get()) {
            throw new \RuntimeException('Já está a correr uma leitura deste relatório para esta empresa.');
        }

        try {
            $days = [];
            $ignored = [];
            $summary = [];
            $calls = 0;
            $chunkStart = $start;
            while ($chunkStart->lte($end)) {
                $chunkEnd = $chunkStart->addDays(self::DAYS_PER_CALL - 1)->min($end);
                if ($calls > 0) {
                    Sleep::for(self::SPACING_SECONDS)->seconds();
                }
                $rows = $this->fetch($companyId, $chunkStart->toDateString(), $chunkEnd->toDateString());
                $calls++;

                $applied = $this->apply($companyId, $chunkStart->toDateString(), $chunkEnd->toDateString(), $rows, $dryRun);
                array_push($days, ...$applied['days']);
                $ignored = array_values(array_unique([...$ignored, ...$applied['ignored_stores']]));
                foreach ($applied[$this->summaryKey] as $key => $cents) {
                    $summary[$key] = ($summary[$key] ?? 0) + $cents;
                }
                $chunkStart = $chunkEnd->addDay();
            }
        } finally {
            $lock->release();
        }

        arsort($summary);
        $result = [
            'company_id' => $companyId,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'dry_run' => $dryRun,
            'calls' => $calls,
            'days' => $days,
            'ignored_stores' => $ignored,
            $this->summaryKey => $summary,
        ];
        Log::info($this->label() . ' leitura', [
            'company_id' => $companyId, 'from' => $result['from'], 'to' => $result['to'], 'dry_run' => $dryRun,
            'calls' => $calls, 'estados' => array_count_values(array_column($days, 'status')),
        ]);

        return $result;
    }

    /**
     * Aplica as linhas de um bloco: por loja ativa × dia do bloco, substitui as linhas (ou
     * protege o dia vazio) e regista o estado da conferência. Com $dryRun não escreve.
     */
    public function apply(int $companyId, string $from, string $to, array $rows, bool $dryRun = false): array
    {
        $locations = PingwinLocation::where('company_id', $companyId)->where('is_active', true)->orderBy('id')->get();
        $byStore = $locations->keyBy(fn (PingwinLocation $l) => trim((string) $l->winrest_store_id));

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
            $grouped[$location->id][$date] ??= [];
            $this->groupRow($row, $grouped[$location->id][$date]);
        }
        if ($ignored !== []) {
            Log::warning($this->label() . ' linhas de lojas não cadastradas (ignoradas)', [
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
        $summary = [];
        $reread = []; // dias cujo Resumo de Vendas já se releu nesta leitura
        $dayModel = $this->dayModel();
        foreach ($locations as $location) {
            // Antes do primeiro mês com vendas detetado, a loja não existia no PingWin: esses
            // dias não se leem nem se marcam. Antes do primeiro dia com vendas, um dia sem
            // linhas é um dia sem vendas (não fica marcado para voltar a ler).
            $firstMonth = $location->sales_first_month?->toDateString();
            $firstSale = $location->sales_since?->toDateString();
            foreach (CarbonPeriod::create($from, $to) as $day) {
                $date = $day->toDateString();
                if ($firstMonth !== null && $date < $firstMonth) {
                    continue;
                }
                $items = $grouped[$location->id][$date] ?? [];
                $daily = $dailyNet[$location->id][$date] ?? null;
                $itemsNet = array_sum(array_column($items, 'net_cents'));
                $status = $this->status($items, $itemsNet, $daily, $firstSale !== null && $date < $firstSale);

                // Antes de marcar um dia que não bate, relê-se o Resumo de Vendas desse dia
                // (um pedido, para todas as lojas) e volta-se a conferir. Um resumo lido antes
                // de o restaurante fechar fica curto (caso de 20/09).
                $dailyReread = false;
                if ($status === PingwinItemSalesDay::STATUS_MISMATCH && ! $dryRun && ! isset($reread[$date])) {
                    $reread[$date] = true;
                    $this->rereadDailyNet($companyId, $date, $locations, $dailyNet);
                    $daily = $dailyNet[$location->id][$date] ?? null;
                    $status = $this->status($items, $itemsNet, $daily, false);
                    $dailyReread = true;
                }

                if (! $dryRun) {
                    DB::transaction(function () use ($companyId, $location, $date, $items, $status, $itemsNet, $daily, $now, $dayModel) {
                        $clear = $items !== [] || $status === PingwinItemSalesDay::STATUS_EMPTY;
                        $this->replaceDay($companyId, $location, $date, $items, $now, $clear);
                        $record = $dayModel::firstOrNew(['location_id' => $location->id, 'business_date' => $date]);
                        $record->fill(['company_id' => $companyId, 'status' => $status, 'rows_count' => count($items),
                            $this->dayNetColumn => $itemsNet, 'daily_net_cents' => $daily, 'synced_at' => $now]);
                        $record->reads_count = $record->exists ? $record->reads_count + 1 : 1;
                        $record->save();
                    });
                }

                $this->summarize($items, $summary);
                $days[] = [
                    'location_id' => $location->id,
                    'location' => $location->display_name ?: $location->winrest_name ?: (string) $location->winrest_store_id,
                    'date' => $date,
                    'rows' => count($items),
                    'items_net_cents' => $itemsNet,
                    'daily_net_cents' => $daily,
                    'status' => $status,
                    'daily_reread' => $dailyReread,
                ];
            }
        }

        return ['days' => $days, 'ignored_stores' => array_values(array_unique($ignored)), $this->summaryKey => $summary];
    }

    /**
     * Relê o Resumo de Vendas de um dia (o mesmo pedido do job das 05:00) e atualiza o
     * líquido diário desse dia em $dailyNet. Uma falha não pára a leitura: o dia fica como
     * estava (e marcado).
     */
    private function rereadDailyNet(int $companyId, string $date, $locations, array &$dailyNet): void
    {
        try {
            Sleep::for(self::SPACING_SECONDS)->seconds();
            $this->pingwin->sync($companyId, $date);
        } catch (\Throwable $e) {
            Log::warning($this->label() . ' releitura do resumo falhou', ['company_id' => $companyId, 'date' => $date, 'error' => $e->getMessage()]);

            return;
        }
        PingwinDailySale::where('company_id', $companyId)
            ->whereIn('location_id', $locations->pluck('id'))
            ->whereRaw('DATE(business_date) = ?', [$date])
            ->get(['location_id', 'net_cents'])
            ->each(function ($s) use (&$dailyNet, $date) {
                $dailyNet[$s->location_id][$date] = (int) $s->net_cents;
            });
    }

    /** Estado de uma loja × dia (ver as constantes de PingwinItemSalesDay). */
    private function status(array $items, int $itemsNet, ?int $daily, bool $beforeFirstSale = false): string
    {
        if ($items === []) {
            // Só um líquido diário a zero (ou estar antes do primeiro dia com vendas da
            // loja) confirma que o dia não teve vendas.
            return ($daily === 0 || $beforeFirstSale) ? PingwinItemSalesDay::STATUS_EMPTY : PingwinItemSalesDay::STATUS_EMPTY_PROTECTED;
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

    protected function cents($value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
