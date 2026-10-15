<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinLocation;
use App\Models\PingwinProductBom;
use App\Models\PingwinProductBomLine;
use App\Models\PingwinProductBomStore;
use App\Models\PingwinStockItem;
use App\Models\PingwinWarehouse;
use App\Models\ProductBomYield;
use App\Models\RestaurantStoreWarehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Fatia 7a: o consumo teórico, calculado pela XPLENDOR a partir do espelho (vendas por
 * artigo e fichas técnicas), sem tocar no PingWin.
 *
 *  · Por dia, loja e componente: vendas do artigo × ficha em vigor nessa data e nessa loja (a mesma
 *    regra das fichas: valid_from mais recente ≤ dia, não anulada, com a loja ativa), na unidade base
 *    do componente: qnt_base × (1 + desperdício/100).
 *  · Preparados da cozinha (semi-produtos): expandidos até 5 níveis, com proteção contra ciclos. A
 *    quantidade usada ÷ rendimento confirmado; sem rendimento confirmado, ÷ 1 e o consumo fica
 *    marcado como de baixa confiança. Um preparado sem ficha em vigor conta como componente.
 *  · Artigo vendido sem ficha em vigor que mexe stock (bebidas vendidas à garrafa, por exemplo):
 *    consome-se a si próprio, na quantidade vendida.
 *  · Loja → armazém por omissão: o PingWin não o tem no formulário da loja nem no armazém (a "loja
 *    principal" do armazém vem vazia na Yuko). O que tem são as lojas que cada armazém serve
 *    (storerelation): se uma loja de vendas é servida por um só armazém ativo, é esse. Senão, ou para
 *    o mudar, define-se na XPLENDOR, por loja (restaurant_store_warehouses), e essa escolha manda.
 *  · Janela: os 28 dias até ao último dia com vendas lidas (não até hoje, para não contar como zero
 *    os dias que ainda não chegaram).
 *  · Unidades: o consumo sai na unidade base; o stock do PingWin vem na unidade de stock (unit_value
 *    em relação à base). Converte-se o consumo ÷ unit_value. Na Yuko, todos os artigos lidos tinham
 *    unit_value 1 (confirmado só nesse caso).
 */
final class StockConsumptionService
{
    public const WINDOW_DAYS = 28;

    public const MAX_DEPTH = 5;

    /** @var array<string, list<array>> */
    private array $versions = [];

    /** @var array<string, float> bom_id → rendimento confirmado */
    private array $yields = [];

    /** @var array<string, bool|null> */
    private array $movestock = [];

    private bool $loaded = false;

    /** @var array<string, array>|null */
    private ?array $storeWarehouses = null;

    public function __construct(private int $companyId) {}

    /**
     * O armazém por omissão de cada loja de vendas (as lojas do PingWin com vendas).
     *
     * @return array<string, array{store_id:string, store_name:?string, warehouse_id:?string, source:?string, candidates:list<string>}>
     */
    public function storeWarehouses(): array
    {
        if ($this->storeWarehouses !== null) {
            return $this->storeWarehouses;
        }
        $warehouses = PingwinWarehouse::where('company_id', $this->companyId)->where('deleted', false)->get();
        $own = RestaurantStoreWarehouse::where('company_id', $this->companyId)->pluck('warehouse_pingwin_id', 'store_pingwin_id')->all();
        $out = [];
        foreach (PingwinLocation::where('company_id', $this->companyId)->orderBy('id')->get() as $loc) {
            $sid = (string) $loc->winrest_store_id;
            $candidates = $warehouses->filter(fn ($w) => in_array($sid, $w->servedStoreIds(), true))->pluck('pingwin_id')->map(fn ($v) => (string) $v)->values()->all();
            $chosen = $own[$sid] ?? null;
            $source = null;
            if ($chosen !== null && $warehouses->contains('pingwin_id', $chosen)) {
                $source = 'xplendor';
            } elseif (count($candidates) === 1) {
                $chosen = $candidates[0];
                $source = 'pingwin';
            } else {
                $chosen = null;
            }
            $out[$sid] = ['store_id' => $sid, 'store_name' => $loc->display_name ?: $loc->winrest_name, 'location_id' => (int) $loc->id,
                'warehouse_id' => $chosen, 'source' => $source, 'candidates' => $candidates];
        }

        return $this->storeWarehouses = $out;
    }

    /** O armazém por omissão de uma loja (null se não estiver definido). */
    public function warehouseForStore(string $storeId): ?string
    {
        return $this->storeWarehouses()[$storeId]['warehouse_id'] ?? null;
    }

    /**
     * A janela de cálculo: os $days dias até ao último dia com vendas lidas.
     *
     * @return array{from:?string, to:?string, days:int}
     */
    public function window(int $days = self::WINDOW_DAYS): array
    {
        $last = DB::table('pingwin_item_sales_daily')->where('company_id', $this->companyId)->max('business_date');
        if (! $last) {
            return ['from' => null, 'to' => null, 'days' => $days];
        }
        $to = CarbonImmutable::parse(substr((string) $last, 0, 10));

        return ['from' => $to->subDays($days - 1)->toDateString(), 'to' => $to->toDateString(), 'days' => $days];
    }

    /**
     * O consumo teórico por dia, loja e componente, na unidade base do componente.
     *
     * @return list<array{date:string, store_id:string, component_id:string, qty:float, via:string, source_id:string, low_confidence:bool}>
     */
    public function daily(string $from, string $to): array
    {
        $this->load();
        $locations = PingwinLocation::where('company_id', $this->companyId)->pluck('winrest_store_id', 'id')->map(fn ($v) => (string) $v)->all();
        $sales = DB::table('pingwin_item_sales_daily')->where('company_id', $this->companyId)
            ->whereBetween('business_date', [$from, $to])
            ->selectRaw('location_id, business_date, product_pingwin_id, SUM(quantity) as qty')
            ->groupBy('location_id', 'business_date', 'product_pingwin_id')->get();

        $acc = [];
        foreach ($sales as $s) {
            $qty = (float) $s->qty;
            $pid = (string) $s->product_pingwin_id;
            $storeId = $locations[(int) $s->location_id] ?? '';
            if ($qty == 0.0 || $pid === '' || $storeId === '') {
                continue;
            }
            $date = substr((string) $s->business_date, 0, 10);
            $parts = [];
            $low = false;
            if ($this->explode($pid, $qty, $date, $storeId, 0, [], $parts, $low)) {
                $via = 'ficha';
            } elseif ($this->movesStock($pid)) {
                $parts = [$pid => $qty];
                $via = 'venda';
            } else {
                continue;
            }
            foreach ($parts as $cid => $q) {
                $k = "{$date}|{$storeId}|{$cid}|{$pid}";
                $acc[$k] ??= ['date' => $date, 'store_id' => $storeId, 'component_id' => (string) $cid, 'qty' => 0.0, 'via' => $via, 'source_id' => $pid, 'low_confidence' => false];
                $acc[$k]['qty'] += $q;
                $acc[$k]['low_confidence'] = $acc[$k]['low_confidence'] || $low;
            }
        }

        return array_values($acc);
    }

    /**
     * O consumo médio diário de cada componente na janela, no total e por armazém (pelas lojas que o
     * consomem), com os artigos vendidos que mais o consomem.
     *
     * @return array{window:array, components:array<string, array{per_day:float, by_warehouse:array<string,float>, unassigned:float, low_confidence:bool, sources:array<string,float>, via:list<string>}>}
     */
    public function averages(int $days = self::WINDOW_DAYS): array
    {
        $window = $this->window($days);
        if (! $window['from']) {
            return ['window' => $window, 'components' => []];
        }
        $out = [];
        foreach ($this->daily($window['from'], $window['to']) as $r) {
            $c = &$out[$r['component_id']];
            $c ??= ['per_day' => 0.0, 'by_warehouse' => [], 'unassigned' => 0.0, 'low_confidence' => false, 'sources' => [], 'via' => []];
            $per = $r['qty'] / $days;
            $c['per_day'] += $per;
            $wid = $this->warehouseForStore($r['store_id']);
            if ($wid !== null) {
                $c['by_warehouse'][$wid] = ($c['by_warehouse'][$wid] ?? 0.0) + $per;
            } else {
                $c['unassigned'] += $per;
            }
            $c['low_confidence'] = $c['low_confidence'] || $r['low_confidence'];
            $c['sources'][$r['source_id']] = ($c['sources'][$r['source_id']] ?? 0.0) + $r['qty'];
            if (! in_array($r['via'], $c['via'], true)) {
                $c['via'][] = $r['via'];
            }
            unset($c);
        }
        foreach ($out as &$c) {
            arsort($c['sources']);
        }

        return ['window' => $window, 'components' => $out];
    }

    /** Os artigos que usam este componente numa ficha em vigor hoje (em alguma loja), diretamente ou num preparado. */
    public function usedInForceBy(string $componentId, ?string $date = null): array
    {
        $this->load();
        $date ??= CarbonImmutable::now('Europe/Lisbon')->toDateString();
        $users = [];
        foreach ($this->versions as $pid => $versions) {
            $v = RecipeCostService::versionInForce($versions, $date);
            if ($v && in_array($componentId, array_column($v['lines'], 'component_id'), true)) {
                $users[] = (string) $pid;
            }
        }

        return $users;
    }

    /** As fichas do espelho, por artigo (para as regras). */
    public function versionsOf(string $productId): array
    {
        $this->load();

        return $this->versions[$productId] ?? [];
    }

    /** @return list<string> os artigos com ficha no espelho */
    public function productsWithBom(): array
    {
        $this->load();

        return array_map('strval', array_keys($this->versions));
    }

    /**
     * Expande a venda de $qty de $pid nos componentes (unidade base), com a ficha em vigor nessa data e
     * loja. Devolve false se o artigo não tiver ficha em vigor.
     */
    private function explode(string $pid, float $qty, string $date, string $storeId, int $depth, array $path, array &$out, bool &$low): bool
    {
        $versions = $this->versions[$pid] ?? [];
        if (! $versions) {
            return false;
        }
        $v = RecipeCostService::versionInForce($versions, $date, $storeId)
            ?? ($depth > 0 ? RecipeCostService::versionInForce($versions, $date) : null);
        if (! $v || ! $v['lines']) {
            return false;
        }
        $divisor = 1.0;
        if ($depth > 0) {
            $yield = $this->yields[$v['bom_id']] ?? null;
            if ($yield !== null && $yield > 0) {
                $divisor = $yield;
            } else {
                $low = true;
            }
        }
        $path[] = $pid;
        foreach ($v['lines'] as $l) {
            $cid = $l['component_id'];
            if ($cid === '') {
                continue;
            }
            $q = $qty * $l['qnt_base'] * (1 + $l['waste'] / 100) / $divisor;
            if ($l['is_bom'] && $depth + 1 < self::MAX_DEPTH && ! in_array($cid, $path, true)
                && $this->explode($cid, $q, $date, $storeId, $depth + 1, $path, $out, $low)) {
                continue;
            }
            $out[$cid] = ($out[$cid] ?? 0.0) + $q;
        }

        return true;
    }

    /** Mexe stock? Pela leitura do stock; sem ela, pela configuração do catálogo (7500 = "Sem stock"). */
    private function movesStock(string $pid): bool
    {
        if (! array_key_exists($pid, $this->movestock)) {
            $this->movestock[$pid] = null;
        }
        if ($this->movestock[$pid] !== null) {
            return $this->movestock[$pid];
        }
        $cfg = PingwinCatalogItem::where('company_id', $this->companyId)->where('pingwin_id', $pid)->value('stockconfig_id');

        return $this->movestock[$pid] = $cfg !== null && $cfg !== '' && (string) $cfg !== '7500';
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $boms = PingwinProductBom::where('company_id', $this->companyId)->get();
        $bomIds = $boms->pluck('bom_pingwin_id')->map(fn ($v) => (string) $v)->all();
        $stores = PingwinProductBomStore::where('company_id', $this->companyId)->get()->groupBy('bom_pingwin_id');
        $lines = PingwinProductBomLine::where('company_id', $this->companyId)->orderBy('line_key')->get()->groupBy('bom_pingwin_id');
        foreach ($boms as $b) {
            $this->versions[(string) $b->product_pingwin_id][] = [
                'bom_id' => (string) $b->bom_pingwin_id, 'key' => (string) $b->version_key,
                'valid_from' => $b->valid_from?->toDateString(), 'deleted' => (bool) $b->deleted,
                'stores' => ($stores[$b->bom_pingwin_id] ?? collect())->map(fn ($s) => [
                    'store_id' => (string) $s->store_pingwin_id, 'code' => $s->store_code, 'name' => $s->store_name, 'active' => (bool) $s->active,
                ])->values()->all(),
                'lines' => ($lines[$b->bom_pingwin_id] ?? collect())->map(fn ($l) => [
                    'component_id' => (string) $l->component_pingwin_id, 'description' => $l->component_description,
                    'qnt_base' => (float) $l->qnt_base, 'waste' => (float) $l->waste_pct, 'is_bom' => (bool) $l->is_bom,
                ])->values()->all(),
            ];
        }
        foreach (array_chunk($bomIds, 500) as $chunk) {
            foreach (ProductBomYield::where('company_id', $this->companyId)->where('source', 'confirmado')->whereIn('bom_pingwin_id', $chunk)->get() as $y) {
                $this->yields[(string) $y->bom_pingwin_id] = (float) $y->quantity;
            }
        }
        foreach (PingwinStockItem::where('company_id', $this->companyId)->whereNotNull('movestock')->get(['product_pingwin_id', 'movestock']) as $i) {
            $this->movestock[(string) $i->product_pingwin_id] = (bool) $i->movestock;
        }
    }
}
