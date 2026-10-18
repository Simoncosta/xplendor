<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Units;

use App\Audit\Rules\Restaurant\RestaurantAuditData;
use App\Models\ProductUnitConversion;
use App\Models\ProductUnitProfile;
use App\Services\Restaurant\RecipeCostService;
use App\Services\Restaurant\RecipeHints;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Auditoria v2: as evidências das unidades de cada artigo, na linguagem do Guião:
 *  · compras (12 meses): por fornecedor e unidade faturada, quantidades, € e preço por unidade
 *    faturada e por unidade base, e a embalagem lida da descrição do fornecedor;
 *  · fichas: em que fichas entra, com que quantidade e unidade, e a fração da base por porção;
 *  · contagens e transferências: em que unidade se contou e se enviou;
 *  · vendas: se é vendido 1 para 1 (bebidas em garrafa ou lata);
 *  · preço por unidade base face à mediana da família;
 *  · as unidades usadas e a última utilização de cada uma;
 *  · a confiança (0 a 1) e as contradições.
 * Nunca usa o unit_value do PingWin como verdade: a quantidade base vem de uma conversão confirmada,
 * da linha já na unidade base, ou da embalagem lida (marcada como "lida da descrição").
 */
final class UnitEvidence
{
    public const WINDOW_RECENT = 90;

    /** @var array<string, array>|null */
    private ?array $all = null;

    private ?array $recipesByComponent = null;

    private ?array $countUnits = null;

    private ?array $transferUnits = null;

    private ?array $profiles = null;

    public function __construct(private RestaurantAuditData $data) {}

    public static function forCompany(int $companyId): self
    {
        return new self(new RestaurantAuditData($companyId));
    }

    public function data(): RestaurantAuditData
    {
        return $this->data;
    }

    public function cost(): RecipeCostService
    {
        return $this->data->cost();
    }

    /** A família de uma unidade (kg | L | un) pela unidade de topo. */
    public function familyOf(?string $unitId): ?string
    {
        $root = $this->cost()->unitRoot($unitId);
        $u = $root ? ($this->cost()->units()[$root] ?? null) : null;

        return $u ? self::fam(RecipeHints::family($u->description, $u->shortname)) : null;
    }

    public static function fam(?string $f): ?string
    {
        return ['KG' => 'kg', 'L' => 'L', 'UN' => 'un'][$f] ?? null;
    }

    /** A família da unidade base do artigo no PingWin (pela base, senão pela unidade de compra). */
    public function baseFamily(string $pid): ?string
    {
        $root = $this->cost()->componentBaseRoot($pid);

        return $root ? $this->familyOf($root) : null;
    }

    /** As evidências de todos os artigos comprados nos últimos 12 meses ou usados em fichas. */
    public function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }
        $this->data->cost()->load();
        $purchases = $this->data->purchases();
        $ids = array_values(array_unique(array_merge(array_keys($purchases), array_keys($this->recipesByComponent()))));
        $this->cost()->ensureItems($ids);
        $out = [];
        foreach ($ids as $pid) {
            $out[(string) $pid] = $this->build((string) $pid);
        }
        $this->addFamilyMedians($out);
        $this->addStockRelevance($out);
        foreach ($out as $pid => $e) {
            $out[$pid]['confidence'] = $this->confidence($out[$pid]);
        }

        return $this->all = $out;
    }

    public function for(string $pid): ?array
    {
        return $this->all()[$pid] ?? null;
    }

    private function build(string $pid): array
    {
        $item = $this->data->item($pid) ?? $this->cost()->item($pid);
        $today = $this->data->today();
        $recentSince = $this->data->daysAgo(self::WINDOW_RECENT);
        $baseFamily = $this->baseFamily($pid);
        $rows = $this->data->purchases()[$pid] ?? [];

        // Compras por fornecedor × unidade faturada.
        $groups = [];
        $descriptions = [];
        foreach ($rows as $r) {
            $key = ($r['nif'] ?: $r['supplier_id']) . '|' . $r['unit_key'];
            $g = $groups[$key] ??= [
                'supplier' => (string) $r['supplier'], 'nif' => (string) $r['nif'], 'unit_key' => (string) $r['unit_key'], 'unit_id' => (string) $r['unit_id'],
                'unit_label' => (string) $r['unit_label'], 'unit_family' => $this->familyOf($r['unit_id']) , 'pingwin_factor' => null,
                'qnt' => 0.0, 'total_eur' => 0.0, 'recent_qnt' => 0.0, 'recent_eur' => 0.0, 'lines' => 0, 'first_date' => $r['date'], 'last_date' => $r['date'],
                'last_doc' => null, 'last_document' => null, 'descriptions' => [], 'confirmed_factor' => null, 'base_qnt' => 0.0, 'base_source' => null,
            ];
            $g['qnt'] += (float) $r['qnt'];
            $g['total_eur'] += (float) $r['total'];
            $g['lines']++;
            if ($r['date'] >= $recentSince) {
                $g['recent_qnt'] += (float) $r['qnt'];
                $g['recent_eur'] += (float) $r['total'];
            }
            $g['last_date'] = max($g['last_date'], $r['date']);
            $g['first_date'] = min($g['first_date'], $r['date']);
            if ($r['date'] >= ($g['last_date'] ?? '')) {
                $g['last_doc'] = $r['docheader_id'];
                $g['last_document'] = $r['document'];
            }
            $d = trim((string) $r['description']);
            if ($d !== '') {
                $g['descriptions'][$d] = ($g['descriptions'][$d] ?? 0) + 1;
                $descriptions[$d] = ($descriptions[$d] ?? 0) + 1;
            }
            if ($r['qty_source'] === 'confirmado' && (float) $r['qnt'] > 0) {
                $g['confirmed_factor'] = round((float) $r['qnt_base'] / (float) $r['qnt'], 6);
            }
            // O fator do PingWin só se mostra (nunca se usa): a quantidade base do raw ÷ quantidade.
            if ((float) $r['qnt'] > 0 && (float) $r['raw_qnt_base'] > 0) {
                $g['pingwin_factor'] = round((float) $r['raw_qnt_base'] / (float) $r['qnt'], 6);
            }
            $groups[$key] = $g;
        }

        $purchases = [];
        foreach ($groups as $g) {
            arsort($g['descriptions']);
            $g['descriptions'] = array_slice(array_keys($g['descriptions']), 0, 4);
            $pack = null;
            // A embalagem: a da descrição do fornecedor; senão a do nome da unidade faturada ("Grade 24").
            $from = null;
            foreach (array_merge($g['descriptions'], [$g['unit_label']]) as $k => $d) {
                if ($p = PackagingParser::parse($d)) {
                    $pack = $p;
                    $from = $k < count($g['descriptions']) ? 'descricao' : 'unidade';
                    break;
                }
            }
            $g['packaging'] = $pack;
            $g['packaging_from'] = $from;
            // A quantidade base desta unidade: confirmada > a unidade já é a base > a embalagem lida.
            [$factor, $source] = $this->baseFactor($g, $baseFamily, $pack);
            $g['base_factor'] = $factor;
            $g['base_source'] = $source;
            $g['price_per_unit'] = $g['qnt'] > 0 ? round($g['total_eur'] / $g['qnt'], 4) : null;
            $g['price_per_base'] = $factor && $g['qnt'] > 0 ? round($g['total_eur'] / ($g['qnt'] * $factor), 4) : null;
            $g['total_eur'] = round($g['total_eur'], 2);
            $g['recent_eur'] = round($g['recent_eur'], 2);
            $purchases[] = $g;
        }
        usort($purchases, fn ($a, $b) => $b['total_eur'] <=> $a['total_eur']);

        // A embalagem do artigo: a mais escrita nas descrições (e, sem compras, a do nome do artigo).
        arsort($descriptions);
        $packaging = null;
        // Só as descrições dos fornecedores e as unidades faturadas: o nome do artigo nunca decide.
        foreach (array_merge(array_keys($descriptions), array_column($purchases, 'unit_label')) as $d) {
            if ($p = PackagingParser::parse($d)) {
                $packaging = $p + ['source' => $d];
                break;
            }
        }

        $recipes = $this->recipesByComponent()[$pid] ?? [];
        $sales = $this->data->sales90()[$pid] ?? null;
        $versions = $this->cost()->versionsOf($pid);
        $units = $this->unitsUsed($pid, $purchases, $recipes);

        return [
            'product_id' => $pid,
            'code' => $item?->code ? ltrim((string) $item->code, "\u{FEFF}") : null,
            'description' => (string) ($item?->description ?? $pid),
            'label' => $this->data->label($pid),
            'family' => RestaurantAuditData::familyPath($item?->family),
            'family_leaf' => RestaurantAuditData::familyLeaf($item?->family),
            'base_unit_id' => $item?->base_unit_id ? (string) $item->base_unit_id : null,
            'base_unit' => $item?->base_unit_id ? ($this->cost()->units()[(string) $item->base_unit_id]->description ?? null) : null,
            'base_family' => $baseFamily,
            'purchase_unit_id' => $item?->default_purchase_unit_id ? (string) $item->default_purchase_unit_id : null,
            'purchases' => $purchases,
            'spend_12m_eur' => round(array_sum(array_column($purchases, 'total_eur')), 2),
            'spend_90d_eur' => round(array_sum(array_column($purchases, 'recent_eur')), 2),
            'packaging' => $packaging,
            'recipes' => $recipes,
            'counts' => $this->countUnits()[$pid] ?? [],
            'transfers' => $this->transferUnits()[$pid] ?? [],
            'sold_qty_90d' => $sales['qty'] ?? 0.0,
            'sold_net_90d_eur' => isset($sales['net']) ? round($sales['net'] / 100, 2) : 0.0,
            'sold_one_to_one' => $sales && ($sales['qty'] ?? 0) > 0 && ! $versions && $purchases !== [],
            'has_recipe' => $versions !== [],
            'units' => $units,
            'stock' => array_map(fn ($s) => ['warehouse' => (string) $s->warehouse_pingwin_id, 'qnt' => (float) $s->qnt, 'unit' => $s->unit_name, 'pcm' => (float) ($s->pcm ?? 0)],
                $this->data->stock()[$pid] ?? []),
            'profile' => $this->profiles()[$pid] ?? null,
            // "Dar mais informação": o texto de uma pessoa conta como mais uma descrição do artigo.
            'extra_descriptions' => array_values(array_filter([trim((string) ($this->profiles()[$pid]['info_text'] ?? ''))])),
            'today' => $today,
        ];
    }

    /**
     * Quantas unidades base vale uma unidade faturada: confirmada (product_unit_conversions) > a
     * unidade é a base do artigo > a embalagem lida da descrição. Devolve [fator, origem].
     */
    private function baseFactor(array $g, ?string $baseFamily, ?array $pack): array
    {
        if ($g['confirmed_factor']) {
            return [$g['confirmed_factor'], 'confirmado'];
        }
        if ($g['unit_family'] && $g['unit_family'] === $baseFamily && $this->cost()->unitRoot($g['unit_id']) === $g['unit_id']) {
            return [1.0, 'base'];
        }
        if ($pack && $pack['quantity'] > 0) {
            if ($baseFamily === $pack['family']) {
                return [$pack['quantity'], 'embalagem'];
            }
            if ($baseFamily === 'un' && $pack['family'] !== 'un' && $pack['count'] > 1) {
                return [(float) $pack['count'], 'embalagem'];
            }
        }

        return [null, null];
    }

    /** Fichas (versões ativas) por componente: produto, quantidade, unidade, fração da base e vendas. */
    private function recipesByComponent(): array
    {
        if ($this->recipesByComponent !== null) {
            return $this->recipesByComponent;
        }
        $this->cost()->load();
        $today = $this->data->today();
        $sales = $this->data->sales90();
        $out = [];
        foreach ($this->cost()->allVersions() as $productId => $versions) {
            $v = RecipeCostService::versionInForce($versions, $today) ?? (collect($versions)->where('deleted', false)->sortBy('valid_from')->last());
            if (! $v) {
                continue;
            }
            foreach ($v['lines'] as $l) {
                if (! $l['component_id']) {
                    continue;
                }
                $out[$l['component_id']][] = [
                    'product_id' => (string) $productId, 'label' => $this->data->label((string) $productId), 'bom_id' => $v['bom_id'],
                    'qnt' => (float) $l['qnt'], 'unit_id' => (string) $l['unit_id'], 'unit' => (string) ($l['unit'] ?? ''),
                    'unit_family' => $this->familyOf($l['unit_id']), 'qnt_base' => (float) $l['qnt_base'],
                    'sold_qty_90d' => (float) ($sales[(string) $productId]['qty'] ?? 0), 'is_bom' => (bool) $l['is_bom'],
                ];
            }
        }

        return $this->recipesByComponent = $out;
    }

    private function countUnits(): array
    {
        if ($this->countUnits === null) {
            $this->countUnits = [];
            $rows = DB::table('stock_count_lines as l')->join('stock_counts as c', 'c.id', '=', 'l.count_id')
                ->where('c.company_id', $this->data->companyId)->where('c.created_at', '>=', $this->data->daysAgo(365))
                ->get(['l.product_id', 'l.entries', 'l.stock_unit', 'c.inventory_date', 'c.created_at']);
            foreach ($rows as $r) {
                $entries = is_string($r->entries) ? (json_decode($r->entries, true) ?: []) : (array) $r->entries;
                foreach ($entries ?: [['unit' => $r->stock_unit]] as $e) {
                    $u = trim((string) ($e['unit'] ?? $r->stock_unit));
                    if ($u === '') {
                        continue;
                    }
                    $slot = &$this->countUnits[(string) $r->product_id][$u];
                    $slot = ['unit' => $u, 'times' => ($slot['times'] ?? 0) + 1, 'last' => max((string) ($slot['last'] ?? ''), substr((string) ($r->inventory_date ?? $r->created_at), 0, 10))];
                    unset($slot);
                }
            }
            $this->countUnits = array_map('array_values', $this->countUnits);
        }

        return $this->countUnits;
    }

    private function transferUnits(): array
    {
        if ($this->transferUnits === null) {
            $this->transferUnits = [];
            $rows = DB::table('stock_transfer_lines as l')->join('stock_transfers as t', 't.id', '=', 'l.transfer_id')
                ->where('t.company_id', $this->data->companyId)->where('t.created_at', '>=', $this->data->daysAgo(365))
                ->get(['l.product_id', 'l.unit_desc', 'l.factor', 't.created_at']);
            foreach ($rows as $r) {
                $u = trim((string) $r->unit_desc);
                if ($u === '') {
                    continue;
                }
                $slot = &$this->transferUnits[(string) $r->product_id][$u];
                $slot = ['unit' => $u, 'factor' => (float) $r->factor, 'times' => ($slot['times'] ?? 0) + 1, 'last' => max((string) ($slot['last'] ?? ''), substr((string) $r->created_at, 0, 10))];
                unset($slot);
            }
            $this->transferUnits = array_map('array_values', $this->transferUnits);
        }

        return $this->transferUnits;
    }

    /** As unidades que o artigo usa e a última vez de cada uma (compras, fichas, contagens, transferências). */
    private function unitsUsed(string $pid, array $purchases, array $recipes): array
    {
        $units = [];
        $touch = function (string $label, ?string $id, string $where, ?string $date) use (&$units) {
            $k = mb_strtolower($label);
            $u = $units[$k] ?? ['id' => $id, 'label' => $label, 'uses' => []];
            $u['id'] ??= $id;
            $u['uses'][$where] = max((string) ($u['uses'][$where] ?? ''), (string) $date);
            $units[$k] = $u;
        };
        foreach ($purchases as $p) {
            $touch($p['unit_label'], $p['unit_id'] ?: null, 'compras', $p['last_date']);
        }
        foreach ($recipes as $r) {
            $touch($r['unit'] ?: $r['unit_id'], $r['unit_id'], 'fichas', $this->data->today());
        }
        foreach ($this->countUnits()[$pid] ?? [] as $c) {
            $touch($c['unit'], null, 'contagens', $c['last']);
        }
        foreach ($this->transferUnits()[$pid] ?? [] as $t) {
            $touch($t['unit'], null, 'transferencias', $t['last']);
        }
        foreach ($units as $k => $u) {
            $units[$k]['last_use'] = max(array_values($u['uses']) ?: ['']) ?: null;
        }

        return array_values($units);
    }

    private function profiles(): array
    {
        if ($this->profiles === null) {
            $this->profiles = ProductUnitProfile::where('company_id', $this->data->companyId)->get()
                ->keyBy(fn ($p) => (string) $p->product_pingwin_id)
                ->map(fn ($p) => ['id' => $p->id, 'state' => $p->state, 'base' => $p->base_confirmed, 'type' => $p->article_type,
                    'count_unit' => $p->count_unit, 'count_factor' => $p->count_factor, 'info_text' => $p->info_text])->all();
        }

        return $this->profiles;
    }

    /**
     * O artigo conta para as unidades (é de stock): a configuração do PingWin diz que mexe stock; ou
     * entra em fichas, contagens ou transferências; ou (sem a configuração lida) é de uma família onde
     * há artigos assim. Fica de fora o que é despesa (eletricidade, serviços), sem nomes no código.
     */
    private function addStockRelevance(array &$out): void
    {
        $families = [];
        foreach ($out as $pid => $e) {
            $cfg = $this->data->item((string) $pid)?->stockconfig_id;
            $known = $cfg !== null && $cfg !== '';
            $used = $e['recipes'] || $e['counts'] || $e['transfers'] || $e['stock'];
            $out[$pid]['stock_relevant'] = $known ? (string) $cfg !== '7500' : ($used ? true : null);
            if ($out[$pid]['stock_relevant'] && $e['family'] !== '') {
                $families[$e['family']] = true;
            }
        }
        foreach ($out as $pid => $e) {
            if ($e['stock_relevant'] === null) {
                $out[$pid]['stock_relevant'] = $e['family'] !== '' && isset($families[$e['family']]);
            }
        }
    }

    /** O preço por unidade base de cada artigo face à mediana dos artigos da mesma família e base. */
    private function addFamilyMedians(array &$out): void
    {
        $byFamily = [];
        foreach ($out as $pid => $e) {
            $p = self::pricePerBase($e);
            if ($p !== null && $e['family'] !== '' && $e['base_family']) {
                $byFamily[$e['family'] . '|' . $e['base_family']][$pid] = $p;
            }
        }
        foreach ($out as $pid => $e) {
            $p = self::pricePerBase($e);
            $peers = $byFamily[$e['family'] . '|' . $e['base_family']] ?? [];
            unset($peers[$pid]);
            $median = count($peers) >= 3 ? RecipeCostService::median(array_values($peers)) : null;
            $out[$pid]['price_per_base'] = $p;
            $out[$pid]['family_median'] = $median ? round($median, 4) : null;
            $out[$pid]['family_peers'] = count($peers);
            $out[$pid]['price_ratio'] = $median && $p ? round($p / $median, 3) : null;
        }
    }

    /** O preço por unidade base do artigo (média ponderada das compras com quantidade base conhecida). */
    public static function pricePerBase(array $e): ?float
    {
        $q = 0.0;
        $t = 0.0;
        foreach ($e['purchases'] as $p) {
            if ($p['base_factor'] && $p['qnt'] > 0) {
                $q += $p['qnt'] * $p['base_factor'];
                $t += $p['total_eur'];
            }
        }

        return $q > 0 && $t > 0 ? round($t / $q, 4) : null;
    }

    /**
     * A confiança (0 a 1): alta quando a embalagem lida, as unidades faturadas e o preço batem; baixa
     * quando se contradizem ou faltam dados. Também devolve as contradições, para o "O que vimos".
     */
    private function confidence(array $e): array
    {
        $score = 0.25;
        $why = [];
        $contra = [];
        $confirmed = array_filter($e['purchases'], fn ($p) => $p['base_source'] === 'confirmado');
        if ($e['purchases'] && count($confirmed) === count($e['purchases'])) {
            return ['score' => 0.95, 'why' => ['Todas as unidades de compra têm o fator confirmado.'], 'contradictions' => []];
        }
        if ($e['packaging']) {
            $score += 0.25;
            $why[] = 'A descrição diz a embalagem (' . $e['packaging']['label'] . ').';
        }
        $known = array_filter($e['purchases'], fn ($p) => $p['base_factor'] !== null);
        if ($e['purchases'] && count($known) === count($e['purchases'])) {
            $score += 0.2;
            $why[] = 'Todas as compras têm a quantidade base conhecida.';
        } elseif ($e['purchases'] && ! $known) {
            $score -= 0.1;
            $contra[] = 'Nenhuma compra tem a quantidade base conhecida.';
        }
        if ($e['price_ratio'] !== null) {
            if ($e['price_ratio'] >= 0.25 && $e['price_ratio'] <= 4) {
                $score += 0.2;
                $why[] = 'O preço por unidade base está dentro do da família.';
            } else {
                // Só uma pista (a regra U5 trata disto): pesa pouco na confiança.
                $score -= 0.1;
                $contra[] = 'O preço por unidade base está longe do da família.';
            }
        }
        $families = array_values(array_unique(array_filter(array_map(fn ($p) => $p['unit_family'], $e['purchases']))));
        if (count($families) > 1 && count($known) < count($e['purchases'])) {
            $score -= 0.2;
            $contra[] = 'Os fornecedores faturam-no em unidades de famílias diferentes (' . implode(', ', $families) . ').';
        }
        if ($e['packaging'] && $e['base_family'] && $e['packaging']['family'] !== 'un' && $e['base_family'] !== 'un' && $e['packaging']['family'] !== $e['base_family']) {
            $score -= 0.25;
            $contra[] = 'A embalagem lida (' . $e['packaging']['family'] . ') não é da família da unidade base (' . $e['base_family'] . ').';
        }
        if (! $e['purchases'] && ! $e['packaging']) {
            $score = min($score, 0.2);
            $contra[] = 'Sem compras nos últimos 12 meses e sem embalagem na descrição.';
        }

        return ['score' => round(max(0.0, min(1.0, $score)), 2), 'why' => $why, 'contradictions' => $contra];
    }
}
