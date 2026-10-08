<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinDailySale;
use App\Models\PingwinItemSale;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use App\Services\Restaurant\FamilyCategoryRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * XPLENDOR — F1-2 do marketing da restauração (documents/PINGWIN-F1-DESENHO.md §4):
 *  · início de cada loja: o relatório anual (uma loja e um ano de cada vez) dá o primeiro
 *    mês com vendas; o dia exato é o primeiro dia com vendas, no fim do histórico;
 *  · histórico: recua em blocos de 7 dias desde o dia mais antigo já espelhado até ao
 *    primeiro mês com vendas, até 10 pedidos por noite, 20 s entre eles;
 *  · releitura dos dias marcados (não batem ou vazios protegidos), até 3 leituras; os dias
 *    antes do primeiro dia com vendas de cada loja não ficam marcados;
 *  · catálogo completo: leitura completa; os vendidos em falta procuram-se nos anulados.
 *
 * Só leituras no PingWin, em série. Quem chama verifica o interruptor da empresa.
 */
class PingwinItemHistoryService
{
    /** Pedidos de histórico (Vendas por artigo) por empresa e por noite. */
    public const CALLS_PER_NIGHT = 10;
    /** Anos a recuar no relatório anual, no máximo. */
    public const MAX_YEARS = 5;
    /** Janela dos artigos vendidos para a cobertura do catálogo. */
    public const CATALOG_COVERAGE_DAYS = 90;

    private int $callsMade = 0;

    public function __construct(
        private readonly PingwinService $pingwin,
        private readonly PingwinItemSalesService $sales,
    ) {}

    /**
     * Noite de uma empresa: deteta o início das lojas por detetar, importa o histórico
     * (até $budget pedidos) e, com o orçamento que sobrar, relê os dias marcados.
     * $afterAnotherCall: houve um pedido ao PingWin imediatamente antes (espaçamento).
     */
    public function nightly(int $companyId, int $budget = self::CALLS_PER_NIGHT, bool $afterAnotherCall = true): array
    {
        $this->callsMade = $afterAnotherCall ? 1 : 0;
        $detection = $this->detectStarts($companyId);
        $backfill = $this->backfill($companyId, $budget);
        $reread = $this->rereadMarked($companyId, $budget - $backfill['calls']);

        return ['detection' => $detection, 'backfill' => $backfill, 'reread' => $reread];
    }

    // ── Início de cada loja ────────────────────────────────────────────────────

    /**
     * Deteta o primeiro mês com vendas das lojas ativas ainda por detetar (ou de todas,
     * com $force). Recua ano a ano enquanto janeiro tiver vendas. Recusa concluir quando o
     * relatório anual dá zero num mês em que o líquido diário espelhado tem vendas.
     */
    public function detectStarts(int $companyId, string $locals = '', bool $force = false): array
    {
        $locations = PingwinLocation::where('company_id', $companyId)->where('is_active', true)
            ->when(! $force, fn ($q) => $q->whereNull('sales_start_checked_at'))
            ->orderBy('id')->get();

        // Postos de venda: os indicados no comando, senão os da configuração da integração
        // (definidos pelo root); vazio = sem filtro (soma todos, confirmado na sessão real).
        $locals = $locals !== '' ? $locals : $this->pingwin->annualLocals($companyId);
        $out = [];
        foreach ($locations as $location) {
            $year = (int) CarbonImmutable::now('Europe/Lisbon')->year;
            $first = null;
            $years = [];
            for ($i = 0; $i < self::MAX_YEARS; $i++, $year--) {
                $this->pause();
                $months = $this->pingwin->fetchStoreYear($companyId, (string) $location->winrest_store_id, $year, $locals);
                $this->callsMade++;
                $this->assertReliable($location, $year, $months);
                $years[$year] = $months;

                $index = null;
                foreach ($months as $m => $value) {
                    if ($value > 0.004) {
                        $index = $m;
                        break;
                    }
                }
                if ($index === null) {
                    break; // ano sem vendas: o início está num ano mais recente
                }
                $first = CarbonImmutable::create($year, $index + 1, 1)->toDateString();
                if ($index > 0) {
                    break; // começou a meio do ano: não há vendas no ano anterior
                }
            }

            $location->forceFill(['sales_first_month' => $first, 'sales_start_checked_at' => now()])->save();
            Log::info('[PingWin Histórico] início detetado', [
                'company_id' => $companyId, 'location_id' => $location->id, 'primeiro_mes' => $first, 'anos_lidos' => array_keys($years),
            ]);
            $out[] = ['location_id' => $location->id, 'location' => $this->name($location), 'first_month' => $first, 'years' => $years];
        }

        return $out;
    }

    /**
     * Salvaguarda: num mês em que o líquido diário espelhado tem vendas, o relatório anual
     * tem de ter vendas. Se não tiver, a leitura não é fiável (por exemplo, os postos de
     * venda pedidos não são todos) e nada se conclui.
     */
    private function assertReliable(PingwinLocation $location, int $year, array $months): void
    {
        $withSales = [];
        PingwinDailySale::where('location_id', $location->id)
            ->whereRaw('DATE(business_date) BETWEEN ? AND ?', ["{$year}-01-01", "{$year}-12-31"])
            ->where('net_cents', '>', 0)
            ->get(['business_date'])
            ->each(function ($s) use (&$withSales) {
                $withSales[(int) substr((string) $s->business_date, 5, 2)] = true;
            });

        foreach (array_keys($withSales) as $month) {
            $monthly = $months[$month - 1] - ($month > 1 ? $months[$month - 2] : 0.0);
            if ($monthly <= 0.004) {
                throw new \RuntimeException(sprintf(
                    'Deteção do início não fiável na loja %s: o relatório anual dá 0 em %02d/%d, mas o líquido diário tem vendas nesse mês.',
                    $this->name($location), $month, $year,
                ));
            }
        }
    }

    // ── Histórico ──────────────────────────────────────────────────────────────

    /**
     * Recua em blocos de 7 dias desde o dia mais antigo já lido até ao primeiro mês com
     * vendas mais antigo das lojas com histórico por fazer. No fim, marca as lojas como
     * completas e grava o primeiro dia com vendas (sales_since).
     */
    public function backfill(int $companyId, int $budget = self::CALLS_PER_NIGHT): array
    {
        $active = PingwinLocation::where('company_id', $companyId)->where('is_active', true)->get();
        $pending = $active->filter(fn (PingwinLocation $l) => $l->sales_first_month !== null && $l->history_complete_at === null);
        if ($pending->isEmpty()) {
            return ['calls' => 0, 'blocks' => [], 'complete' => true, 'target' => null, 'reached' => null];
        }

        $target = CarbonImmutable::parse($pending->min(fn (PingwinLocation $l) => $l->sales_first_month->toDateString()));
        $oldest = PingwinItemSalesDay::whereIn('location_id', $active->pluck('id'))->min('business_date');
        $cursor = $oldest ? CarbonImmutable::parse(substr((string) $oldest, 0, 10)) : CarbonImmutable::today();

        $calls = 0;
        $blocks = [];
        while ($calls < $budget && $cursor->gt($target)) {
            $end = $cursor->subDay();
            $start = $end->subDays(PingwinItemSalesService::DAYS_PER_CALL - 1)->max($target);
            $this->pause();
            $result = $this->sales->sync($companyId, $start->toDateString(), $end->toDateString());
            $this->callsMade++;
            $calls++;
            $blocks[] = [
                'from' => $start->toDateString(), 'to' => $end->toDateString(),
                'statuses' => array_count_values(array_column($result['days'], 'status')),
            ];
            $cursor = $start;
        }

        $complete = $cursor->lte($target);
        if ($complete) {
            foreach ($pending as $location) {
                $since = PingwinItemSale::where('location_id', $location->id)->where('net_cents', '>', 0)->min('business_date');
                $location->forceFill([
                    'history_complete_at' => now(),
                    'sales_since' => $since ? substr((string) $since, 0, 10) : null,
                ])->save();
            }
            $this->settlePreStartDays($companyId);
        }
        Log::info('[PingWin Histórico] noite', [
            'company_id' => $companyId, 'pedidos' => $calls, 'chegou_a' => $cursor->toDateString(),
            'alvo' => $target->toDateString(), 'completo' => $complete,
        ]);

        return ['calls' => $calls, 'blocks' => $blocks, 'complete' => $complete,
            'target' => $target->toDateString(), 'reached' => $cursor->toDateString()];
    }

    /**
     * Relê os dias marcados (não batem ou vazios protegidos) anteriores à janela da noite,
     * lidos antes de hoje e com menos de 3 leituras, em blocos de até 7 dias, dentro do
     * orçamento.
     */
    public function rereadMarked(int $companyId, int $budget): array
    {
        $this->settlePreStartDays($companyId);
        if ($budget <= 0) {
            return ['calls' => 0, 'blocks' => []];
        }
        [$windowStart] = PingwinItemSalesService::nightlyWindow();
        $dates = PingwinItemSalesDay::where('company_id', $companyId)
            ->whereIn('status', PingwinItemSalesDay::STATUSES_TO_REREAD)
            ->where('reads_count', '<', PingwinItemSalesDay::MAX_READS)
            ->where('business_date', '<', $windowStart)
            ->where('synced_at', '<', CarbonImmutable::today()) // não reler o que já se leu hoje
            ->orderBy('business_date')
            ->pluck('business_date')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->unique()->values();

        $calls = 0;
        $blocks = [];
        $coveredUntil = null;
        $lastAllowed = CarbonImmutable::parse($windowStart)->subDay();
        foreach ($dates as $date) {
            if ($calls >= $budget) {
                break;
            }
            if ($coveredUntil !== null && $date <= $coveredUntil) {
                continue;
            }
            $start = CarbonImmutable::parse($date);
            $end = $start->addDays(PingwinItemSalesService::DAYS_PER_CALL - 1)->min($lastAllowed);
            $this->pause();
            $this->sales->sync($companyId, $start->toDateString(), $end->toDateString());
            $this->callsMade++;
            $calls++;
            $coveredUntil = $end->toDateString();
            $blocks[] = ['from' => $start->toDateString(), 'to' => $coveredUntil];
        }

        return ['calls' => $calls, 'blocks' => $blocks];
    }

    /**
     * Os dias vazios antes do primeiro dia com vendas de cada loja são dias sem vendas: saem
     * de "vazio protegido" para "sem vendas" e não voltam a ler-se. Devolve quantos mudaram.
     */
    public function settlePreStartDays(int $companyId): int
    {
        $changed = 0;
        $locations = PingwinLocation::where('company_id', $companyId)->whereNotNull('sales_since')->get();
        foreach ($locations as $location) {
            $changed += PingwinItemSalesDay::where('location_id', $location->id)
                ->where('business_date', '<', $location->sales_since->toDateString())
                ->where('status', PingwinItemSalesDay::STATUS_EMPTY_PROTECTED)
                ->update(['status' => PingwinItemSalesDay::STATUS_EMPTY]);
        }

        return $changed;
    }

    // ── Catálogo completo ──────────────────────────────────────────────────────

    /**
     * Catálogo completo: a leitura completa (um pedido, que traz também os anulados). Os
     * artigos vendidos nos últimos 90 dias que não estão entre os ativos procuram-se nos
     * anulados: os que lá estiverem entram no espelho como anulados (is_active = false),
     * com o nome, o código e a família das vendas, e contam como cobertos. Sem leitura
     * família a família.
     */
    public function syncCatalogComplete(int $companyId): array
    {
        $missingBefore = self::missingSoldProductIds($companyId);
        $this->pause();
        $count = $this->pingwin->syncCatalog($companyId, true);
        $this->callsMade++;
        $diagnostics = $this->pingwin->lastCatalogDiagnostics;

        $deleted = array_flip($this->pingwin->lastCatalogDeletedIds);
        $annulled = array_values(array_filter(self::missingSoldProductIds($companyId), fn ($id) => isset($deleted[$id])));
        $now = now();
        foreach ($annulled as $productId) {
            $last = PingwinItemSale::where('company_id', $companyId)->where('product_pingwin_id', $productId)
                ->orderByDesc('business_date')->first();
            PingwinCatalogItem::updateOrCreate(
                ['company_id' => $companyId, 'pingwin_id' => $productId],
                [
                    'code' => $last?->product_code,
                    'description' => $last?->product_name,
                    'family' => $last?->family_path ? FamilyCategoryRules::leaf($last->family_path) : null,
                    'family_pingwin_id' => $last?->family_pingwin_id,
                    'is_active' => false, // anulado no PingWin
                    'synced_at' => $now,
                ],
            );
        }
        $missingAfter = self::missingSoldProductIds($companyId);
        $sold = self::soldProductIds($companyId);

        $result = [
            'count' => $count,
            'diagnostics' => $diagnostics,
            'sold' => count($sold),
            'missing_before' => count($missingBefore),
            'annulled_added' => count($annulled),
            'annulled_sample' => array_slice($annulled, 0, 10),
            'missing_after' => count($missingAfter),
            'missing_sample' => array_slice($missingAfter, 0, 10),
        ];
        Log::info('[PingWin Catálogo] leitura completa', ['company_id' => $companyId] + array_diff_key($result, ['diagnostics' => 1]));

        return $result;
    }

    /** IDs dos artigos vendidos nos últimos 90 dias (vendas por artigo). */
    public static function soldProductIds(int $companyId, int $days = self::CATALOG_COVERAGE_DAYS): array
    {
        $from = CarbonImmutable::today()->subDays($days)->toDateString();

        return PingwinItemSale::where('company_id', $companyId)->where('business_date', '>=', $from)
            ->distinct()->orderBy('product_pingwin_id')->pluck('product_pingwin_id')->all();
    }

    /** Artigos vendidos nos últimos 90 dias que não estão no catálogo espelhado. */
    public static function missingSoldProductIds(int $companyId, int $days = self::CATALOG_COVERAGE_DAYS): array
    {
        $sold = self::soldProductIds($companyId, $days);
        if ($sold === []) {
            return [];
        }
        $known = [];
        foreach (array_chunk($sold, 500) as $chunk) {
            array_push($known, ...PingwinCatalogItem::where('company_id', $companyId)->whereIn('pingwin_id', $chunk)->pluck('pingwin_id')->all());
        }

        return array_values(array_diff($sold, $known));
    }

    /** 20 s entre pedidos seguidos ao PingWin (nada antes do primeiro). */
    private function pause(): void
    {
        if ($this->callsMade > 0) {
            Sleep::for(PingwinItemSalesService::SPACING_SECONDS)->seconds();
        }
    }

    private function name(PingwinLocation $location): string
    {
        return $location->display_name ?: $location->winrest_name ?: (string) $location->winrest_store_id;
    }
}
