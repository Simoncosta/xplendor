<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Menu;

use App\Models\MenuEngineeringExclusion;
use App\Models\MenuEngineeringItem;
use App\Models\MenuEngineeringSetting;
use App\Models\MenuEngineeringSnapshot;
use App\Models\PingwinLocation;
use App\Services\Restaurant\RecipeCostService;
use App\Services\SupplierDocumentLinesService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Fatia 3: engenharia de menu, cobertura de custos e food cost (só leitura).
 *
 * Custo de cada prato: a mesma função do ecrã de Fichas técnicas (RecipeCostService::costVersion: compras
 * de fornecedor por unidade base, fatores confirmados na XPLENDOR, desperdício da linha, semi-produtos ÷
 * rendimento confirmado). Aqui, a versão é a que estava em vigor em cada dia e loja de venda, e o custo
 * do período é a média pesada pela quantidade vendida em cada versão ("Ficha alterada no período" se
 * houve mais do que uma). NUNCA o PCM nem o unit_value do PingWin.
 *
 * Confiança: Confirmado (ficha e todos os ingredientes com preço e conversão), Parcial (falta algum: o
 * custo é um mínimo), Sem custo (sem ficha em vigor). Não se criam ligações venda → compra à mão.
 */
final class MenuEngineeringService
{
    public const CONFIDENCE = ['confirmado', 'parcial', 'sem_custo'];

    public const EXCLUSIONS = [
        'preco_zero' => 'Preço 0 (modificador)',
        'pouco_vendido' => 'Pouco vendido no período',
        'retirado' => 'Sem vendas nos últimos 30 dias',
        'uber_eats' => 'Canal Uber Eats (outros preços)',
        'manual' => 'Excluído à mão',
    ];

    private CarbonImmutable $today;

    private array $cfg;

    public function __construct(private int $companyId, ?CarbonInterface $today = null)
    {
        $this->today = CarbonImmutable::parse(($today ?? now('Europe/Lisbon'))->toDateString());
        $this->cfg = (array) config('menu_engineering');
    }

    /** O período por omissão: os últimos N dias até ontem. @return array{0:string,1:string} */
    public function defaultPeriod(): array
    {
        $to = $this->today->subDay();

        return [$to->subDays((int) ($this->cfg['period_days'] ?? 90) - 1)->toDateString(), $to->toDateString()];
    }

    /** "Família \ Comidas \ Francesinhas" → "Comidas › Francesinhas". */
    public static function familyLabel(?string $path): string
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/\\\\|›/u', (string) $path) ?: []), fn ($p) => $p !== ''));
        if ($parts && mb_strtolower($parts[0]) === 'família') {
            array_shift($parts);
        }

        return $parts ? implode(' › ', $parts) : 'Sem família';
    }

    /**
     * Calcula e grava o snapshot (substitui o anterior da mesma chave).
     */
    public function snapshot(string $from, string $to, ?int $locationId, string $mode): MenuEngineeringSnapshot
    {
        $res = $this->compute($from, $to, $locationId, $mode);

        return DB::transaction(function () use ($res, $from, $to, $locationId, $mode) {
            $snap = MenuEngineeringSnapshot::create([
                'company_id' => $this->companyId, 'period_from' => $from, 'period_to' => $to, 'location_id' => $locationId,
                'price_mode' => $mode, 'status' => 'ok', 'data' => $res['data'], 'calculated_at' => now(),
            ]);
            foreach (array_chunk($res['items'], 200) as $chunk) {
                MenuEngineeringItem::insert(array_map(fn ($it) => [
                    'snapshot_id' => $snap->id, 'product_id' => $it['product_id'], 'code' => $it['code'], 'description' => mb_substr((string) $it['description'], 0, 255),
                    'family' => mb_substr($it['family'], 0, 120), 'qty' => $it['qty'], 'revenue_cents' => $it['revenue_cents'], 'tax_cents' => $it['tax_cents'],
                    'price' => $it['price'], 'cost' => $it['cost'], 'margin' => $it['margin'], 'margin_total' => $it['margin_total'],
                    'food_cost_pct' => $it['food_cost_pct'], 'share_pct' => $it['share_pct'], 'revenue_share_pct' => $it['revenue_share_pct'],
                    'confidence' => $it['confidence'], 'class' => $it['class'], 'popular' => $it['popular'], 'high_margin' => $it['high_margin'],
                    'fragile' => $it['fragile'], 'excluded' => $it['excluded'], 'pareto' => $it['pareto'], 'detail' => json_encode($it['detail'], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
            // Os anteriores da mesma chave (e os com mais de 14 dias) saem.
            MenuEngineeringSnapshot::where('company_id', $this->companyId)->where('id', '<>', $snap->id)
                ->where(fn ($q) => $q->where(fn ($k) => $k->where('price_mode', $mode)->whereDate('period_from', $from)->whereDate('period_to', $to)
                    ->when($locationId === null, fn ($x) => $x->whereNull('location_id'), fn ($x) => $x->where('location_id', $locationId)))
                    ->orWhere('calculated_at', '<', now()->subDays(14)))->delete();

            return $snap;
        });
    }

    /**
     * O cálculo inteiro (sem gravar). @return array{items: list<array>, data: array}
     */
    public function compute(string $from, string $to, ?int $locationId, string $mode): array
    {
        $mode = in_array($mode, RecipeCostService::PRICE_MODES, true) ? $mode : 'media';
        $locations = PingwinLocation::where('company_id', $this->companyId)->get()->keyBy('id');
        $settings = MenuEngineeringSetting::for($this->companyId);
        $manual = MenuEngineeringExclusion::where('company_id', $this->companyId)->pluck('reason', 'product_id')->mapWithKeys(fn ($r, $k) => [(string) $k => $r])->all();

        // 1. Vendas por artigo, dia e loja.
        $rows = DB::table('pingwin_item_sales_daily')->where('company_id', $this->companyId)->whereBetween('business_date', [$from, $to])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('product_pingwin_id', 'business_date', 'location_id')
            ->orderBy('business_date')
            ->get(['product_pingwin_id', 'business_date', 'location_id', DB::raw('SUM(quantity) as qty'), DB::raw('SUM(net_cents) as net'),
                DB::raw('SUM(tax_cents) as tax'), DB::raw('MAX(product_name) as name'), DB::raw('MAX(product_code) as code'), DB::raw('MAX(family_path) as family')]);
        $products = [];
        foreach ($rows as $r) {
            $pid = (string) $r->product_pingwin_id;
            $p = &$products[$pid];
            $p ??= ['qty' => 0.0, 'net' => 0, 'tax' => 0, 'name' => $r->name, 'code' => $r->code, 'family_path' => $r->family, 'last' => null, 'sales' => []];
            $p['qty'] += (float) $r->qty;
            $p['net'] += (int) $r->net;
            $p['tax'] += (int) $r->tax;
            $p['family_path'] = $r->family ?: $p['family_path'];
            $p['name'] = $r->name ?: $p['name'];
            if ((float) $r->qty > 0) {
                $p['last'] = max((string) $p['last'], substr((string) $r->business_date, 0, 10));
                $store = (string) ($locations[$r->location_id]->winrest_store_id ?? '');
                $p['sales'][] = [substr((string) $r->business_date, 0, 10), $store, (float) $r->qty];
            }
            unset($p);
        }

        // 2. O custo (a função das Fichas), com o preço até ao fim do período.
        $cost = (new RecipeCostService($this->companyId, CarbonImmutable::parse($to)))->withPriceMode($mode);
        $cost->load(array_map('strval', array_keys($products)));

        $items = [];
        foreach ($products as $pid => $p) {
            $items[] = $this->costItem((string) $pid, $p, $cost);
        }

        // 3. Exclusões.
        $familyQty = [];
        foreach ($items as $it) {
            if ($it['price'] !== null && $it['price'] > 0.004) {
                $familyQty[$it['family']] = ($familyQty[$it['family']] ?? 0) + $it['qty'];
            }
        }
        $retiredSince = CarbonImmutable::parse($to)->subDays((int) ($this->cfg['retired_days'] ?? 30) - 1)->toDateString();
        $uber = mb_strtolower((string) ($this->cfg['uber_family'] ?? 'Uber Eats'));
        foreach ($items as &$it) {
            $reason = null;
            $minQty = max((float) ($this->cfg['min_qty'] ?? 5), ($familyQty[$it['family']] ?? 0) * (float) ($this->cfg['min_family_share_pct'] ?? 1) / 100);
            if (isset($manual[$it['product_id']])) {
                $reason = 'manual';
            } elseif (! $settings->include_zero_price && ($it['price'] === null || $it['price'] <= 0.004)) {
                $reason = 'preco_zero';
            } elseif (! $settings->include_uber_eats && str_contains(mb_strtolower($it['family']), $uber)) {
                $reason = 'uber_eats';
            } elseif (! $settings->include_retired && ($it['last_sale'] === null || $it['last_sale'] < $retiredSince)) {
                $reason = 'retirado';
            } elseif (! $settings->include_low_volume && $it['qty'] < $minQty) {
                $reason = 'pouco_vendido';
            }
            $it['excluded'] = $reason;
            $it['detail']['excluded_reason'] = $reason ? ($reason === 'manual' ? 'Excluído à mão: ' . $manual[$it['product_id']] : self::EXCLUSIONS[$reason]) : null;
            if ($reason === 'pouco_vendido') {
                $it['detail']['excluded_reason'] .= sprintf(' (menos de %s unidades)', rtrim(rtrim(number_format($minQty, 1, ',', ''), '0'), ','));
            }
        }
        unset($it);

        // 4. A matriz (só os incluídos).
        $matrix = MenuMatrix::classify(array_map(fn ($i) => [
            'id' => $i['product_id'], 'family' => $i['family'], 'qty' => $i['qty'], 'revenue' => $i['revenue_cents'] / 100,
            'price' => $i['price'], 'cost' => $i['cost'], 'confidence' => $i['confidence'],
        ], array_values(array_filter($items, fn ($i) => $i['excluded'] === null))), $this->cfg);
        $plays = (array) ($this->cfg['plays'] ?? []);
        foreach ($items as &$it) {
            $m = $matrix['items'][$it['product_id']] ?? null;
            $it['share_pct'] = $m['share_pct'] ?? null;
            $it['popular'] = $m['popular'] ?? null;
            $it['high_margin'] = $m['high_margin'] ?? null;
            $it['class'] = $m['class'] ?? null;
            $it['fragile'] = (bool) ($m['fragile'] ?? false);
            $it['detail']['fragile_reasons'] = $m['fragile_reasons'] ?? [];
            $it['detail']['play'] = $it['class'] ? ($plays[$it['class']] ?? null) : null;
        }
        unset($it);

        // 5. Pareto, cobertura e lista de trabalho (sobre toda a receita vendida).
        usort($items, fn ($a, $b) => [$b['revenue_cents'], $a['description']] <=> [$a['revenue_cents'], $b['description']]);
        $total = array_sum(array_map(fn ($i) => max(0, $i['revenue_cents']), $items));
        $cum = 0;
        $byConf = ['confirmado' => 0, 'parcial' => 0, 'sem_custo' => 0];
        $points = [];
        foreach ($items as $i => &$it) {
            $it['pareto'] = $i + 1;
            $rev = max(0, $it['revenue_cents']);
            $cum += $rev;
            $it['revenue_share_pct'] = $total > 0 ? round($rev / $total * 100, 3) : null;
            $byConf[$it['confidence']] += $rev;
            if ($rev > 0) {
                $points[] = ['rank' => $i + 1, 'product_id' => $it['product_id'], 'name' => $it['description'], 'revenue_eur' => round($rev / 100, 2),
                    'cum_pct' => round($cum / $total * 100, 2), 'confidence' => $it['confidence'], 'class' => $it['class']];
            }
        }
        unset($it);
        $pct = fn (int $c) => $total > 0 ? round($c / $total * 100, 1) : null;
        $costedRev = $byConf['confirmado'] + $byConf['parcial'];
        $target = (float) ($this->cfg['compare_min_coverage_pct'] ?? 80);
        $fichasTo80 = 0;
        $reach = $costedRev;
        foreach ($items as $it) {
            if ($total <= 0 || $reach / $total * 100 >= $target) {
                break;
            }
            if ($it['confidence'] === 'sem_custo' && $it['revenue_cents'] > 0) {
                $reach += $it['revenue_cents'];
                $fichasTo80++;
            }
        }
        $sellers = array_values(array_filter($points, fn ($p) => $p['revenue_eur'] > 0));
        $n80 = 0;
        foreach ($sellers as $p) {
            $n80++;
            if ($p['cum_pct'] >= 80) {
                break;
            }
        }

        // 6. Food cost teórico (artigos com custo) e por compras (empresa toda).
        $theoryCost = 0.0;
        $theoryRev = 0;
        foreach ($items as $it) {
            if ($it['cost'] !== null && $it['confidence'] !== 'sem_custo' && $it['revenue_cents'] > 0) {
                $theoryCost += $it['cost'] * $it['qty'];
                $theoryRev += $it['revenue_cents'];
            }
        }
        $purchases = $this->purchaseFoodCost($to);
        $coverage = $pct($costedRev);

        $classes = array_fill_keys(MenuMatrix::CLASSES, 0);
        foreach ($items as $it) {
            if ($it['class'] && $it['excluded'] === null) {
                $classes[$it['class']]++;
            }
        }
        $excluded = [];
        foreach ($items as $it) {
            if ($it['excluded']) {
                $excluded[$it['excluded']] = ($excluded[$it['excluded']] ?? 0) + 1;
            }
        }
        $worklist = $this->worklist($items);
        $top5 = array_map(fn ($it) => ['product_id' => $it['product_id'], 'description' => $it['description'], 'family' => $it['family'],
            'revenue_eur' => round($it['revenue_cents'] / 100, 2), 'cost_eur' => $it['cost'], 'class' => $it['class'], 'confidence' => $it['confidence'],
            'excluded' => $it['excluded']], array_slice($items, 0, 5));
        $location = $locationId ? ($locations[$locationId] ?? null) : null;

        $data = [
            'period' => ['from' => $from, 'to' => $to, 'days' => CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1],
            'location' => $location ? ['id' => $location->id, 'name' => $location->display_name ?: $location->winrest_name] : null,
            'price_mode' => $mode,
            'settings' => $settings->only(MenuEngineeringSetting::TOGGLES),
            'summary' => [
                'revenue_eur' => round($total / 100, 2),
                'articles' => count($items),
                'with_bom' => count(array_filter($items, fn ($i) => $i['has_bom'])),
                'included' => count(array_filter($items, fn ($i) => $i['excluded'] === null)),
                'excluded' => $excluded,
                'coverage' => ['confirmado_pct' => $pct($byConf['confirmado']), 'parcial_pct' => $pct($byConf['parcial']), 'sem_custo_pct' => $pct($byConf['sem_custo']),
                    'with_cost_pct' => $coverage],
                'fichas_to_target' => $fichasTo80, 'coverage_target_pct' => $target,
                'food_cost_theoretical' => ['pct' => $theoryRev > 0 ? round($theoryCost / ($theoryRev / 100) * 100, 1) : null, 'coverage_pct' => $coverage],
                'food_cost_purchases' => $purchases['latest'],
                'compare_allowed' => $coverage !== null && $coverage >= $target,
                'classes' => $classes,
                'pareto' => ['articles' => count($sellers), 'top_articles' => $n80, 'top_articles_pct' => count($sellers) ? round($n80 / count($sellers) * 100, 1) : null, 'revenue_pct' => 80],
                'top5' => $top5,
            ],
            'families' => array_values($matrix['families']),
            'food_cost' => $purchases,
            'pareto' => $points,
            'worklist' => $worklist,
        ];

        return ['items' => $items, 'data' => $data];
    }

    /** O custo do período de um artigo (versões por dia e loja de venda), a confiança e o que falta. */
    private function costItem(string $pid, array $p, RecipeCostService $cost): array
    {
        $qty = (float) $p['qty'];
        $net = (int) $p['net'];
        $price = $qty > 0 ? round($net / 100 / $qty, 4) : null;
        $versions = $cost->versionsOf($pid);
        $detail = ['versions' => [], 'missing' => [], 'stale' => [], 'warnings' => [], 'uncovered_qty' => 0.0, 'reason' => null,
            'vat_pct' => $net > 0 ? round($p['tax'] / $net * 100, 1) : null];
        $unit = null;
        $confidence = 'sem_custo';
        if (! $versions) {
            $detail['reason'] = 'sem_ficha';
        } else {
            $perVersion = [];
            $map = [];
            foreach ($p['sales'] as [$date, $store, $q]) {
                $v = RecipeCostService::versionInForce($versions, $date, $store !== '' ? $store : null);
                if (! $v) {
                    $detail['uncovered_qty'] += $q;

                    continue;
                }
                $perVersion[$v['bom_id']] = ($perVersion[$v['bom_id']] ?? 0) + $q;
                $map[$v['bom_id']] = $v;
            }
            if (! $perVersion) {
                $detail['reason'] = 'sem_ficha_em_vigor';
            } else {
                $sum = 0.0;
                $qv = 0.0;
                $complete = $detail['uncovered_qty'] <= 0;
                foreach ($perVersion as $bom => $q) {
                    $c = $cost->costVersion($map[$bom], $pid);
                    $sum += $q * (float) ($c['cost'] ?? 0);
                    $qv += $q;
                    $complete = $complete && $c['complete'] && ! $c['low_confidence'] && $c['cost'] !== null;
                    $detail['versions'][] = ['bom_id' => $bom, 'valid_from' => $map[$bom]['valid_from'], 'qty' => round($q, 3), 'cost' => $c['cost'], 'complete' => $c['complete']];
                    foreach ($c['lines'] as $l) {
                        $this->missingFrom($l, $detail);
                    }
                    foreach ($c['warnings'] as $w) {
                        if (in_array($w['type'], ['header_discount', 'outliers', 'unconverted', 'old_cost', 'pingwin_diff'], true)) {
                            $detail['warnings'][$w['type'] . '|' . ($w['component_id'] ?? '')] = ['type' => $w['type'], 'text' => $w['text'], 'line' => $w['line'] ?? null];
                        }
                    }
                }
                $unit = $qv > 0 ? round($sum / $qv, 4) : null;
                $confidence = $complete ? 'confirmado' : 'parcial';
                if ($detail['uncovered_qty'] > 0) {
                    $detail['missing'][] = ['component_id' => $pid, 'code' => $p['code'], 'description' => 'Ficha em vigor numa das lojas', 'reason' => 'loja_sem_ficha'];
                }
            }
        }
        $detail['missing'] = array_values($detail['missing']);
        $detail['stale'] = array_values($detail['stale']);
        $detail['warnings'] = array_values($detail['warnings']);
        $detail['changed'] = count($detail['versions']) > 1;
        $margin = $unit !== null && $price !== null && $confidence !== 'sem_custo' ? round($price - $unit, 4) : null;
        $item = $cost->item($pid);

        return [
            'product_id' => $pid, 'code' => $item?->code ? ltrim((string) $item->code, "\u{FEFF}") : $p['code'], 'description' => $item?->description ?? $p['name'] ?? $pid,
            'family' => self::familyLabel($p['family_path']), 'qty' => round($qty, 3), 'revenue_cents' => $net, 'tax_cents' => (int) $p['tax'],
            'price' => $price, 'cost' => $confidence === 'sem_custo' ? null : $unit, 'margin' => $margin,
            'margin_total' => $margin !== null ? round($margin * $qty, 2) : null,
            'food_cost_pct' => $margin !== null && $price > 0 ? round($unit / $price * 100, 2) : null,
            'confidence' => $confidence, 'has_bom' => (bool) $versions, 'last_sale' => $p['last'], 'detail' => $detail,
            'share_pct' => null, 'revenue_share_pct' => null, 'popular' => null, 'high_margin' => null, 'class' => null, 'fragile' => false, 'excluded' => null, 'pareto' => 0,
        ];
    }

    /** O que falta numa linha da ficha (para a confiança e para a lista de trabalho). */
    private function missingFrom(array $l, array &$detail): void
    {
        $key = (string) $l['component_id'];
        $base = ['component_id' => $key, 'code' => $l['code'], 'description' => $l['description']];
        $status = $l['cost_status'];
        if ($l['cost_source'] === 'compras' && in_array($status, ['sem_custo', 'antigo'], true)) {
            $detail['stale'][$key] = $base + ['status' => $status, 'last_purchase' => $l['last_purchase']];
        }
        $reason = match (true) {
            $status === 'sem_conversao' => 'sem_conversao',
            $l['cost_source'] === 'compras' && $status === 'sem_custo' => 'sem_compras',
            $l['cost_source'] === 'ficha' && in_array($status, ['ciclo', 'fundo'], true) => 'ciclo',
            $l['cost_source'] === 'ficha' && $status === 'sem_custo' => 'semi_sem_custo',
            $l['cost_source'] === 'ficha' && $l['sub'] && ! ($l['sub']['yield']['confirmed'] ?? false) => 'semi_sem_rendimento',
            $l['cost_source'] === 'ficha' && ($status === 'parcial' || $l['low_confidence']) => 'semi_parcial',
            default => null,
        };
        if ($reason !== null) {
            $detail['missing'][$key] ??= $base + ['reason' => $reason, 'unit' => $l['conversion']['unit_label'] ?? null];
        }
    }

    /** A lista de trabalho: os artigos Sem custo e Parcial pela receita, com a ação certa. */
    private function worklist(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            if ($it['confidence'] === 'confirmado' || $it['revenue_cents'] <= 0) {
                continue;
            }
            $actions = [];
            if ($it['confidence'] === 'sem_custo') {
                $actions[] = $it['has_bom']
                    ? ['type' => 'ver_ficha', 'product_id' => $it['product_id'], 'label' => 'Ver a ficha', 'text' => 'Tem ficha, mas nenhuma em vigor nas lojas e dias das vendas.']
                    : ['type' => 'criar_ficha', 'product_id' => $it['product_id'], 'label' => 'Criar ficha', 'text' => 'Sem ficha técnica.'];
            }
            foreach ($it['detail']['missing'] as $m) {
                $actions[] = match ($m['reason']) {
                    'sem_conversao' => ['type' => 'confirmar_fator', 'product_id' => $m['component_id'], 'label' => 'Confirmar fator',
                        'text' => sprintf('%s: compras em «%s» sem fator confirmado.', $m['description'], $m['unit'] ?? 'outra unidade')],
                    'sem_compras' => ['type' => 'ver_auditoria', 'product_id' => $m['component_id'], 'search' => (string) ($m['code'] ?: $m['description']), 'label' => 'Ver na auditoria',
                        'text' => sprintf('%s: sem compras.', $m['description'])],
                    'loja_sem_ficha' => ['type' => 'ver_ficha', 'product_id' => $it['product_id'], 'label' => 'Ver a ficha', 'text' => 'Uma das lojas vendeu sem ficha em vigor.'],
                    default => ['type' => 'ver_ficha', 'product_id' => $m['component_id'], 'label' => 'Ver o semi-produto',
                        'text' => sprintf('%s: %s.', $m['description'], match ($m['reason']) {
                            'semi_sem_rendimento' => 'rendimento por confirmar',
                            'ciclo' => 'ciclo ou demasiados níveis',
                            'semi_sem_custo' => 'o semi-produto não tem custo',
                            default => 'o semi-produto tem ingredientes sem custo',
                        })],
                };
            }
            $out[] = ['product_id' => $it['product_id'], 'description' => $it['description'], 'family' => $it['family'], 'confidence' => $it['confidence'],
                'revenue_eur' => round($it['revenue_cents'] / 100, 2), 'revenue_share_pct' => $it['revenue_share_pct'], 'cost_eur' => $it['cost'], 'actions' => $actions];
        }

        return $out;
    }

    /**
     * Food cost por compras (empresa toda; não precisa de fichas): compras líquidas sem IVA das famílias
     * de compras configuradas (fatura + fatura-recibo − nota de crédito) ÷ vendas sem IVA, por mês, com a
     * média móvel de 3 meses e a separação comida/bebida. Os meses vão do primeiro com vendas até ao do fim.
     */
    public function purchaseFoodCost(string $to): array
    {
        $docs = (array) ($this->cfg['purchase_docconfigs'] ?? ['1209' => 1, '584955579139752236' => 1, '1205' => -1]);
        $groups = (array) ($this->cfg['purchase_families'] ?? []);
        $exclude = array_map('mb_strtolower', (array) ($this->cfg['purchase_exclude'] ?? []));
        $families = DB::table('pingwin_families')->where('company_id', $this->companyId)->get(['pingwin_id', 'description', 'parent_pingwin_id'])->keyBy('pingwin_id');
        $pathOf = function (?string $id) use ($families) {
            $parts = [];
            for ($i = 0; $id !== null && $id !== '' && isset($families[$id]) && $i < 8; $i++) {
                array_unshift($parts, trim((string) $families[$id]->description));
                $id = $families[$id]->parent_pingwin_id;
            }

            return implode(' › ', $parts);
        };
        $groupOf = [];
        $classify = function (?string $famId) use (&$groupOf, $pathOf, $groups, $exclude) {
            $famId = (string) $famId;
            if (array_key_exists($famId, $groupOf)) {
                return $groupOf[$famId];
            }
            $path = $pathOf($famId);
            $low = mb_strtolower($path);
            foreach ($exclude as $x) {
                if ($x !== '' && str_contains($low, $x)) {
                    return $groupOf[$famId] = null;
                }
            }
            foreach ($groups as $g => $prefixes) {
                foreach ((array) $prefixes as $prefix) {
                    if (str_contains($path, (string) $prefix)) {
                        return $groupOf[$famId] = $g;
                    }
                }
            }

            return $groupOf[$famId] = null;
        };

        $first = DB::table('pingwin_item_sales_daily')->where('company_id', $this->companyId)->min('business_date');
        if (! $first) {
            return ['months' => [], 'latest' => null, 'groups' => array_keys($groups)];
        }
        $start = CarbonImmutable::parse($first)->startOfMonth()->toDateString();
        $end = CarbonImmutable::parse($to)->toDateString();
        $lines = DB::table('pingwin_supplier_document_lines as l')
            ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
            ->leftJoin('pingwin_catalog_items as i', fn ($j) => $j->on('i.pingwin_id', '=', 'l.product_pingwin_id')->on('i.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $this->companyId)->whereIn('d.docconfig_id', array_map('strval', array_keys($docs)))
            ->where('d.docstatus_id', SupplierDocumentLinesService::STATUS_CLOSED)->whereBetween('d.doc_date', [$start, $end])
            ->get(['d.docconfig_id', 'd.doc_date', 'l.total_cents', 'i.family_pingwin_id']);
        $buy = [];
        foreach ($lines as $l) {
            $g = $classify($l->family_pingwin_id);
            if ($g === null) {
                continue;
            }
            $m = substr((string) $l->doc_date, 0, 7);
            $buy[$m][$g] = ($buy[$m][$g] ?? 0) + (int) ($docs[(string) $l->docconfig_id] ?? 1) * (int) $l->total_cents;
        }
        $sales = DB::table('pingwin_item_sales_daily')->where('company_id', $this->companyId)->whereBetween('business_date', [$start, $end])
            ->selectRaw('substr(business_date, 1, 7) as m, SUM(net_cents) as net')->groupBy('m')->pluck('net', 'm')->map(fn ($v) => (int) $v)->all();

        $months = [];
        $lastDay = CarbonImmutable::parse($end);
        for ($m = CarbonImmutable::parse($start); $m->lte($lastDay); $m = $m->addMonth()) {
            $key = $m->format('Y-m');
            $rev = $sales[$key] ?? 0;
            $row = ['month' => $key, 'revenue_eur' => round($rev / 100, 2), 'complete' => $m->endOfMonth()->toDateString() <= $lastDay->toDateString()];
            $tot = 0;
            foreach (array_keys($groups) as $g) {
                $v = $buy[$key][$g] ?? 0;
                $tot += $v;
                $row[$g . '_eur'] = round($v / 100, 2);
                $row[$g . '_pct'] = $rev > 0 ? round($v / $rev * 100, 1) : null;
            }
            $row['total_eur'] = round($tot / 100, 2);
            $row['total_pct'] = $rev > 0 ? round($tot / $rev * 100, 1) : null;
            $months[] = $row + ['_buy' => $tot, '_rev' => $rev];
        }
        foreach ($months as $i => &$row) {
            $win = array_slice($months, max(0, $i - 2), min(3, $i + 1));
            $b = array_sum(array_column($win, '_buy'));
            $r = array_sum(array_column($win, '_rev'));
            $row['avg3_pct'] = count($win) === 3 && $r > 0 ? round($b / $r * 100, 1) : null;
        }
        unset($row);
        $complete = array_values(array_filter($months, fn ($r) => $r['complete'] && $r['avg3_pct'] !== null));
        $latest = $complete ? end($complete) : null;
        $months = array_map(fn ($r) => array_diff_key($r, ['_buy' => 1, '_rev' => 1]), $months);

        return [
            'months' => $months, 'groups' => array_keys($groups),
            'latest' => $latest ? ['month' => $latest['month'], 'avg3_pct' => $latest['avg3_pct'], 'total_pct' => $latest['total_pct']] : null,
            'families' => $groups, 'excluded_families' => (array) ($this->cfg['purchase_exclude'] ?? []),
        ];
    }

    /**
     * O detalhe de um artigo (ao clicar na matriz): as versões usadas, o custo por ingrediente e de onde
     * veio cada preço (fornecedor, documento, data).
     */
    public function itemDetail(string $pid, string $from, string $to, ?int $locationId, string $mode): array
    {
        $cost = (new RecipeCostService($this->companyId, CarbonImmutable::parse($to)))->withPriceMode($mode);
        $cost->load([$pid]);
        $locations = PingwinLocation::where('company_id', $this->companyId)->pluck('winrest_store_id', 'id');
        $sales = DB::table('pingwin_item_sales_daily')->where('company_id', $this->companyId)->where('product_pingwin_id', $pid)
            ->whereBetween('business_date', [$from, $to])->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('business_date', 'location_id')->get(['business_date', 'location_id', DB::raw('SUM(quantity) as qty')]);
        $used = [];
        foreach ($sales as $s) {
            $v = RecipeCostService::versionInForce($cost->versionsOf($pid), substr((string) $s->business_date, 0, 10), (string) ($locations[$s->location_id] ?? '') ?: null);
            if ($v) {
                $used[$v['bom_id']] = ['version' => $v, 'qty' => ($used[$v['bom_id']]['qty'] ?? 0) + (float) $s->qty];
            }
        }
        $out = [];
        foreach ($used as $bom => $u) {
            $c = $cost->costVersion($u['version'], $pid);
            $out[] = [
                'bom_id' => $bom, 'valid_from' => $u['version']['valid_from'], 'qty' => round($u['qty'], 3), 'cost_eur' => $c['cost'], 'complete' => $c['complete'],
                'lines' => array_map(fn ($l) => [
                    'component_id' => $l['component_id'], 'code' => $l['code'], 'description' => $l['description'], 'qnt_base' => $l['qnt_base'], 'unit' => $l['unit'],
                    'waste' => $l['waste'], 'cost_source' => $l['cost_source'], 'cost_status' => $l['cost_status'], 'unit_cost_eur' => $l['unit_cost'] ?? null,
                    'line_cost_eur' => $l['line_cost'], 'qty_source' => $l['qty_source'], 'last_purchase' => $l['last_purchase'],
                    'warnings' => array_values(array_map(fn ($w) => $w['text'], $l['warnings'])),
                    'purchases' => $l['cost_source'] === 'compras' ? array_map(fn ($r) => [
                        'date' => $r['date'], 'document' => $r['document'], 'supplier' => $r['supplier'], 'qnt' => $r['qnt'], 'unit' => $r['unit_label'],
                        'total_eur' => $r['total'], 'qnt_base' => $r['strict_qnt_base'], 'unit_price_eur' => $r['strict_qnt_base'] ? round($r['total'] / $r['strict_qnt_base'], 4) : null,
                        'source' => $r['qty_source'] === 'confirmado' ? 'fator confirmado' : ($r['strict_qnt_base'] !== null ? 'unidade base' : 'sem conversão'),
                        'header_discount' => $r['header_discount'],
                    ], array_slice(array_reverse($cost->purchaseRowsOf($l['component_id'])), 0, 6)) : [],
                    'semi' => $l['sub'] ? ['product_id' => $l['component_id'], 'yield' => $l['sub']['yield']['quantity'] ?? null, 'yield_confirmed' => $l['sub']['yield']['confirmed'] ?? false,
                        'cost_per_unit_eur' => $l['sub']['cost_per_unit'] ?? null] : null,
                ], $c['lines']),
            ];
        }

        return ['product_id' => $pid, 'description' => $cost->item($pid)?->description, 'versions' => $out, 'price_mode' => $mode];
    }
}
