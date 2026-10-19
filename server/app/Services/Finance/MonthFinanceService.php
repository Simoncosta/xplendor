<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\FinanceClassification;
use App\Models\PingwinDocumentConfig;
use App\Models\PingwinSupplierCcDocument;
use App\Services\Restaurant\Menu\MenuEngineeringService;
use App\Services\SupplierCcService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Finanças › Mês: resultado e tesouraria num só ecrã (SÓ LEITURA, sobre os espelhos).
 *
 *  · Gasto é quando a fatura chega (data da fatura, sem IVA); pago é quando o dinheiro sai.
 *  · Família de cada gasto: a das linhas; sem família, a habitual do fornecedor; sem nenhuma das
 *    duas, a classificação manual (por documento ou para o fornecedor); senão "Por classificar".
 *  · Vendas: as vendas por artigo (sem IVA), a mesma base da Engenharia de menu (e o food cost é o
 *    dela). Por loja só as vendas: as compras entram no Economato, por isso o resultado é da empresa.
 *  · Dinheiro: a conta corrente (S1). O por pagar é Σ por pagar × sinal dos documentos que não são
 *    pagos no ato, como o saldo real da conta corrente, por isso bate por construção, fornecedor a
 *    fornecedor. A data em que uma fatura foi paga não vem no PingWin (a nota de liquidação não diz
 *    que faturas paga): é a da nota de liquidação com o mesmo valor, ou, sem ela, estimada pela ordem
 *    das notas (a mais antiga paga a mais antiga).
 */
final class MonthFinanceService
{
    private const MONTHS = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    private array $cfg;

    private CarbonImmutable $today;

    private FinanceClassifier $classifier;

    /** @var array<string, array{group: string, path: string}> entidade → família habitual */
    private array $habitual = [];

    /** @var array<string, FinanceClassification> */
    private array $manual = [];

    public function __construct(private readonly int $companyId, ?CarbonInterface $today = null)
    {
        $this->cfg = (array) config('finance_month');
        $this->today = CarbonImmutable::parse(($today ?? now('Europe/Lisbon'))->toDateString());
        $this->classifier = new FinanceClassifier($companyId, $this->cfg);
    }

    public static function monthLabel(string $ym): string
    {
        [$y, $m] = array_map('intval', explode('-', $ym));

        return self::MONTHS[$m] . ' de ' . $y;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // O ecrã
    // ─────────────────────────────────────────────────────────────────────────

    public function month(string $ym, ?int $locationId = null): array
    {
        $start = CarbonImmutable::parse($ym . '-01');
        $end = $start->endOfMonth()->startOfDay();
        $from6 = $start->subMonths(5);

        $docs = $this->expenseDocuments($from6->toDateString(), $end->toDateString());
        $this->loadHabitual($end);
        $this->manual = FinanceClassification::where('company_id', $this->companyId)->get()->keyBy('key')->all();
        $parts = $this->parts($docs);

        $months = [];
        for ($m = $from6; $m->lte($start); $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }
        $sales = $this->sales($from6->toDateString(), $end->toDateString(), null);
        $storeSales = $locationId ? $this->sales($start->toDateString(), $end->toDateString(), $locationId) : null;

        $byMonth = [];
        foreach ($months as $mm) {
            $byMonth[$mm] = $this->aggregate($docs->filter(fn ($d) => substr((string) $d->doc_date, 0, 7) === $mm), $parts);
        }
        $cur = $byMonth[$ym];
        $prevKey = $start->subMonth()->format('Y-m');
        $avgKeys = [$start->subMonths(3)->format('Y-m'), $start->subMonths(2)->format('Y-m'), $prevKey];
        $salesNet = $sales[$ym]['net'] ?? 0;
        $result = $salesNet - $cur['expenses'];

        $monthDocs = $docs->filter(fn ($d) => substr((string) $d->doc_date, 0, 7) === $ym)->values();
        $cash = $this->cash($ym, $start, $end, $locationId);
        $documents = $this->documentRows($monthDocs, $parts, $cash['states']);
        $foodCost = $this->foodCost($ym, $end);
        $vat = $this->vat($ym, $monthDocs, $sales);
        $open = array_values(array_filter($documents, fn ($r) => $r['open']));

        $summary = [
            'sales_cents' => $salesNet,
            'expenses_cents' => $cur['expenses'],
            'result_cents' => $result,
            'result_pct' => $salesNet > 0 ? round($result / $salesNet * 100, 1) : null,
            'paid_cents' => $cash['paid_in_month_cents'],
            'due_by_month_end_cents' => $cash['due_by_month_end_cents'],
        ];
        $prevSales = $sales[$prevKey]['net'] ?? 0;
        $prevResult = $prevSales - $byMonth[$prevKey]['expenses'];
        $warnings = $this->warnings($byMonth, $ym, $avgKeys, $sales, $end);

        return [
            'month' => $ym,
            'month_label' => self::monthLabel($ym),
            'today' => $this->today->toDateString(),
            'sentence' => $this->sentence($ym, $summary),
            'explain' => 'Gasto é quando a fatura chega; pago é quando o dinheiro sai.',
            'warnings' => $warnings,
            'stores' => $this->stores(),
            'store_id' => $locationId,
            'cards' => [
                'sales' => [
                    'cents' => $salesNet, 'store_cents' => $storeSales[$ym]['net'] ?? null,
                    'days' => $sales[$ym]['days'] ?? 0, 'days_in_month' => (int) $end->format('j'),
                    'source' => 'Vendas por artigo do PingWin, sem IVA (a mesma base da Engenharia de menu).',
                ],
                'expenses' => [
                    'cents' => $cur['expenses'], 'open_cents' => array_sum(array_column($open, 'net_cents')), 'open_count' => count($open),
                    'unclassified_cents' => $cur['groups'][FinanceClassifier::UNCLASSIFIED]['cents'] ?? 0,
                    'source' => 'Faturas, faturas-recibo, notas de débito e de crédito de fornecedor, pela data da fatura, sem IVA; sem os documentos anulados e sem o que fica fora do resultado.',
                ],
                'result' => [
                    'cents' => $result, 'pct' => $summary['result_pct'], 'prev_cents' => $prevResult,
                    'prev_pct' => $prevSales > 0 ? round($prevResult / $prevSales * 100, 1) : null,
                    'source' => 'Vendas sem IVA menos gastos sem IVA, da empresa toda.',
                ],
                'cash' => [
                    'paid_cents' => $cash['paid_in_month_cents'], 'due_by_month_end_cents' => $cash['due_by_month_end_cents'],
                    'overdue_cents' => $cash['overdue_cents'],
                    'source' => 'Pago: notas de liquidação e faturas-recibo do mês. Por pagar e vencido: a conta corrente dos fornecedores (sincronizada a ' . ($cash['cc_synced_at'] ?? '—') . ').',
                ],
            ],
            'result' => [
                'rows' => $this->resultRows($byMonth, $ym, $prevKey, $avgKeys, $sales),
                'out_of_result' => $cur['out'],
                'food_cost' => $foodCost,
                'chart' => array_map(fn ($mm) => ['month' => $mm, 'label' => self::monthLabel($mm), 'sales_cents' => $sales[$mm]['net'] ?? 0,
                    'expenses_cents' => $byMonth[$mm]['expenses'], 'result_cents' => ($sales[$mm]['net'] ?? 0) - $byMonth[$mm]['expenses']], $months),
                'vat' => $vat,
                'store_note' => 'O resultado é da empresa toda: por loja só as vendas, porque as compras entram no Economato.',
            ],
            'cash' => $cash['view'],
            'documents' => [
                'rows' => $documents,
                'unclassified' => array_values(array_filter($documents, fn ($r) => $r['unclassified_cents'] !== 0)),
                'open' => ['count' => count($open), 'total_cents' => array_sum(array_column($open, 'total_cents')), 'net_cents' => array_sum(array_column($open, 'net_cents'))],
            ],
            'groups' => collect($this->cfg['groups'])->map(fn ($g, $k) => ['key' => $k, 'label' => $g['label']])->values()->all(),
        ];
    }

    /** "Setembro de 2026: vendas de X €, gastos de Y €, resultado de Z € (W %). Saíram A €; faltam pagar B € até ao fim do mês." */
    public function sentence(string $ym, array $s): string
    {
        $pct = $s['result_pct'] === null ? '' : ' (' . self::pct($s['result_pct']) . ')';

        return sprintf('%s: vendas de %s, gastos de %s, resultado de %s%s. Saíram %s; faltam pagar %s até ao fim do mês.',
            ucfirst(self::monthLabel($ym)), self::eur($s['sales_cents']), self::eur($s['expenses_cents']), self::eur($s['result_cents']), $pct,
            self::eur($s['paid_cents']), self::eur($s['due_by_month_end_cents']));
    }

    public static function eur(int $cents): string
    {
        $neg = $cents < 0;

        return ($neg ? '−' : '') . number_format(abs($cents) / 100, 2, ',', ' ') . ' €';
    }

    public static function pct(float $v): string
    {
        return ($v < 0 ? '−' : '') . number_format(abs($v), 1, ',', ' ') . ' %';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Gastos: documentos, partes por família e agregados
    // ─────────────────────────────────────────────────────────────────────────

    /** Os documentos de gasto (sem anulados) de [from, to]. */
    public function expenseDocuments(string $from, string $to): Collection
    {
        return DB::table('pingwin_supplier_documents')->where('company_id', $this->companyId)
            ->whereIn('docconfig_id', array_map('strval', array_keys((array) $this->cfg['expense_docconfigs'])))
            ->where(fn ($q) => $q->whereNull('docstatus_id')->orWhere('docstatus_id', '!=', (string) $this->cfg['voided_status']))
            ->whereBetween('doc_date', [$from, $to])
            ->orderBy('doc_date')->orderBy('document')
            ->get(['docheader_id', 'docconfig_id', 'doctype', 'document', 'entity_pingwin_id', 'entity_name', 'doc_date', 'due_date', 'total_cents',
                'total_tax_cents', 'total_products_cents', 'paid', 'docstatus_id', 'docstatus_description', 'lines_status', 'docreference_number', 'store_name']);
    }

    /** A família habitual de cada fornecedor: a de maior valor nas linhas dele nos últimos N meses. */
    private function loadHabitual(CarbonImmutable $end): void
    {
        $from = $end->subMonths((int) ($this->cfg['habitual_months'] ?? 12))->toDateString();
        $rows = DB::table('pingwin_supplier_document_lines as l')
            ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
            ->leftJoin('pingwin_catalog_items as i', fn ($j) => $j->on('i.pingwin_id', '=', 'l.product_pingwin_id')->on('i.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $this->companyId)->whereBetween('d.doc_date', [$from, $end->toDateString()])
            ->where('d.docstatus_id', '!=', (string) $this->cfg['voided_status'])
            ->whereNotNull('i.family_pingwin_id')
            ->selectRaw('d.entity_pingwin_id as e, i.family_pingwin_id as f, SUM(ABS(l.total_cents)) as v')
            ->groupBy('d.entity_pingwin_id', 'i.family_pingwin_id')->get();
        $best = [];
        foreach ($rows as $r) {
            $path = $this->classifier->path($r->f);
            $c = $this->classifier->classify($path);
            if ($c === null || $c['group'] === 'fora') {
                continue;
            }
            if (! isset($best[$r->e]) || (int) $r->v > $best[$r->e]['v']) {
                $best[$r->e] = ['v' => (int) $r->v, 'group' => $c['group'], 'path' => $path];
            }
        }
        $this->habitual = array_map(fn ($b) => ['group' => $b['group'], 'path' => $b['path']], $best);
    }

    /**
     * As partes de cada documento (grupo, família, cêntimos sem IVA com o sinal do documento, de onde
     * veio a classificação). O que o cabeçalho tem a mais ou a menos que as linhas (acerto, descontos
     * do cabeçalho) fica na parte maior.
     *
     * @return array<string, list<array{group:string, path:string, cents:int, source:string, reason:?string}>>
     */
    public function parts(Collection $docs): array
    {
        $lines = DB::table('pingwin_supplier_document_lines as l')
            ->leftJoin('pingwin_catalog_items as i', fn ($j) => $j->on('i.pingwin_id', '=', 'l.product_pingwin_id')->on('i.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $this->companyId)->whereIn('l.docheader_id', $docs->pluck('docheader_id')->all())
            ->orderBy('l.line_number')
            ->get(['l.docheader_id', 'l.total_cents', 'l.description', 'i.description as article', 'i.family_pingwin_id'])
            ->groupBy('docheader_id');
        $signs = (array) $this->cfg['expense_docconfigs'];
        $out = [];
        foreach ($docs as $d) {
            $sign = (int) ($signs[(string) $d->docconfig_id] ?? 1);
            $net = self::net($d);
            $parts = [];
            foreach ($lines->get($d->docheader_id, collect()) as $l) {
                $path = $this->classifier->path($l->family_pingwin_id);
                $c = $this->classifier->classify($path, trim(($l->article ?? '') . ' ' . ($l->description ?? '')));
                $parts[] = $this->part($d, $c, $path, (int) $l->total_cents, 'linha');
            }
            if ($parts === []) {
                $parts[] = $this->part($d, null, '', $net, 'documento');
            } else {
                $rest = $net - array_sum(array_column($parts, 'cents'));
                if ($rest !== 0) {
                    $big = 0;
                    foreach ($parts as $k => $p) {
                        $big = abs($p['cents']) > abs($parts[$big]['cents']) ? $k : $big;
                    }
                    $parts[$big]['cents'] += $rest;
                }
            }
            $out[$d->docheader_id] = array_map(fn ($p) => ['cents' => $p['cents'] * $sign] + $p, $parts);
        }

        return $out;
    }

    /** Uma parte: a família da linha; sem ela, a habitual do fornecedor; depois a manual; senão por classificar. */
    private function part(object $d, ?array $c, string $path, int $cents, string $kind): array
    {
        if ($c !== null) {
            return ['group' => $c['group'], 'path' => $path, 'cents' => $cents, 'source' => ($c['by'] ?? null) === 'descricao' ? 'deposito' : 'linha', 'reason' => $c['reason']];
        }
        $e = (string) $d->entity_pingwin_id;
        if (isset($this->habitual[$e])) {
            return ['group' => $this->habitual[$e]['group'], 'path' => $this->habitual[$e]['path'], 'cents' => $cents, 'source' => 'fornecedor',
                'reason' => $kind === 'documento' ? 'Documento sem linhas lidas: pela família habitual do fornecedor.' : 'Linha sem família: pela família habitual do fornecedor.'];
        }
        $m = $this->manual['doc:' . $d->docheader_id] ?? $this->manual['sup:' . $e] ?? null;
        if ($m) {
            return ['group' => $m->group, 'path' => '', 'cents' => $cents, 'source' => $m->scope === FinanceClassification::DOCUMENT ? 'manual_documento' : 'manual_fornecedor',
                'reason' => 'Classificado à mão na XPLENDOR.'];
        }

        return ['group' => FinanceClassifier::UNCLASSIFIED, 'path' => '', 'cents' => $cents, 'source' => 'por_classificar',
            'reason' => $kind === 'documento'
                ? 'Documento sem linhas lidas e o fornecedor não tem família habitual.'
                : 'Linha sem artigo (ou artigo sem família) e o fornecedor não tem família habitual.'];
    }

    /** Sem IVA: total − IVA do cabeçalho; sem o IVA lido (documento sem linhas lidas), o total. */
    public static function net(object $d): int
    {
        return $d->total_tax_cents !== null ? (int) $d->total_cents - (int) $d->total_tax_cents : (int) $d->total_cents;
    }

    /** Gastos de um conjunto de documentos: por grupo e família, e o que fica fora do resultado. */
    private function aggregate(Collection $docs, array $parts): array
    {
        $groups = [];
        $out = [];
        $expenses = 0;
        foreach ($docs as $d) {
            foreach ($parts[$d->docheader_id] ?? [] as $p) {
                if ($p['group'] === 'fora') {
                    $label = $p['source'] === 'deposito' ? 'Depósitos de vasilhame (pela descrição do artigo)' : (FinanceClassifier::label($p['path']) ?: 'Depósitos');
                    $out[$label] ??= ['label' => $label, 'cents' => 0, 'reason' => $p['reason']];
                    $out[$label]['cents'] += $p['cents'];
                    continue;
                }
                $expenses += $p['cents'];
                $g = $p['group'];
                $f = FinanceClassifier::label($p['path']) ?: ($g === FinanceClassifier::UNCLASSIFIED ? 'Por classificar' : 'Classificado à mão');
                $groups[$g]['cents'] = ($groups[$g]['cents'] ?? 0) + $p['cents'];
                $groups[$g]['families'][$f] = ($groups[$g]['families'][$f] ?? 0) + $p['cents'];
            }
        }

        return ['expenses' => $expenses, 'groups' => $groups, 'out' => array_values($out)];
    }

    /** A tabela do separador Resultado: grupo e família, valor, % das vendas, mês anterior e média de 3 meses. */
    private function resultRows(array $byMonth, string $ym, string $prevKey, array $avgKeys, array $sales): array
    {
        $labels = collect($this->cfg['groups'])->map(fn ($g) => $g['label'])->all() + [FinanceClassifier::UNCLASSIFIED => 'Por classificar'];
        $sale = fn ($m) => $sales[$m]['net'] ?? 0;
        $avgSales = array_sum(array_map($sale, $avgKeys)) / 3;
        $row = function (string $label, callable $value, string $kind, ?string $group = null) use ($byMonth, $ym, $prevKey, $avgKeys, $sale, $avgSales) {
            $v = (int) $value($byMonth[$ym]);
            $p = (int) $value($byMonth[$prevKey] ?? ['groups' => [], 'expenses' => 0]);
            $avg = (int) round(array_sum(array_map(fn ($m) => $value($byMonth[$m] ?? ['groups' => [], 'expenses' => 0]), $avgKeys)) / 3);

            return ['kind' => $kind, 'group' => $group, 'label' => $label, 'cents' => $v,
                'pct' => $sale($ym) > 0 ? round($v / $sale($ym) * 100, 1) : null,
                'prev_cents' => $p, 'prev_pct' => $sale($prevKey) > 0 ? round($p / $sale($prevKey) * 100, 1) : null,
                'avg3_cents' => $avg, 'avg3_pct' => $avgSales > 0 ? round($avg / $avgSales * 100, 1) : null];
        };
        $rows = [];
        foreach (array_keys($labels) as $g) {
            if ($g === 'fora') {
                continue;
            }
            $families = [];
            foreach (array_merge([$ym, $prevKey], $avgKeys) as $m) {
                $families += array_fill_keys(array_keys($byMonth[$m]['groups'][$g]['families'] ?? []), true);
            }
            if ($families === [] && $g === FinanceClassifier::UNCLASSIFIED) {
                continue;
            }
            $rows[] = $row($labels[$g], fn ($x) => $x['groups'][$g]['cents'] ?? 0, 'grupo', $g);
            $fr = [];
            foreach (array_keys($families) as $f) {
                $fr[] = $row($f, fn ($x) => $x['groups'][$g]['families'][$f] ?? 0, 'familia', $g);
            }
            usort($fr, fn ($a, $b) => abs($b['cents']) <=> abs($a['cents']));
            array_push($rows, ...$fr);
        }
        $rows[] = $row('Total dos gastos', fn ($x) => $x['expenses'], 'total');

        return $rows;
    }

    /**
     * Avisos dos dados: um grupo muito abaixo da média dos 3 meses anteriores (pode faltar lançar
     * documentos, como a folha de salários) e as vendas com dias em falta.
     */
    private function warnings(array $byMonth, string $ym, array $avgKeys, array $sales, CarbonImmutable $end): array
    {
        $out = [];
        $ratio = (float) ($this->cfg['warn_below_avg_ratio'] ?? 0.5);
        foreach ($this->cfg['groups'] as $g => $def) {
            if ($g === 'fora') {
                continue;
            }
            $avg = array_sum(array_map(fn ($m) => $byMonth[$m]['groups'][$g]['cents'] ?? 0, $avgKeys)) / 3;
            $v = $byMonth[$ym]['groups'][$g]['cents'] ?? 0;
            if ($avg >= 100000 && $v < $avg * $ratio) {
                $out[] = sprintf('%s: %s no mês, contra %s de média nos 3 meses anteriores. Confirme se falta lançar documentos no PingWin (por exemplo, a folha de salários ou de obrigações).',
                    $def['label'], self::eur($v), self::eur((int) round($avg)));
            }
        }
        $days = $sales[$ym]['days'] ?? 0;
        $want = $end->lt($this->today) ? (int) $end->format('j') : (int) $this->today->subDay()->format('j');
        if ($days < $want) {
            $out[] = sprintf('Vendas: há vendas por artigo em %d dos %d dias do mês.', $days, $want);
        }

        return $out;
    }

    /** O food cost do mês: o da Engenharia de menu (compras de comida e bebidas ÷ vendas). */
    private function foodCost(string $ym, CarbonImmutable $end): array
    {
        $fc = (new MenuEngineeringService($this->companyId, $this->today))->purchaseFoodCost($end->toDateString());
        $row = collect($fc['months'] ?? [])->firstWhere('month', $ym);

        return ['pct' => $row['total_pct'] ?? null, 'comida_pct' => $row['comida_pct'] ?? null, 'bebida_pct' => $row['bebida_pct'] ?? null,
            'avg3_pct' => $row['avg3_pct'] ?? null,
            'source' => 'Compras de comida e bebidas (faturas e faturas-recibo menos notas de crédito, fechadas) ÷ vendas sem IVA: o mesmo cálculo da Engenharia de menu.'];
    }

    /** IVA estimado: o das vendas menos o dedutível das compras do mês. */
    private function vat(string $ym, Collection $monthDocs, array $sales): array
    {
        $signs = (array) $this->cfg['expense_docconfigs'];
        $buy = 0;
        $unknown = 0;
        foreach ($monthDocs as $d) {
            if ($d->total_tax_cents === null) {
                $unknown++;
                continue;
            }
            $buy += (int) ($signs[(string) $d->docconfig_id] ?? 1) * (int) $d->total_tax_cents;
        }
        $sale = $sales[$ym]['tax'] ?? 0;

        return ['sales_vat_cents' => $sale, 'purchases_vat_cents' => $buy, 'estimate_cents' => $sale - $buy, 'docs_without_vat' => $unknown,
            'note' => 'Estimativa; a declaração é do contabilista.'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Vendas
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{net:int, tax:int, days:int}> */
    private function sales(string $from, string $to, ?int $locationId): array
    {
        return DB::table('pingwin_item_sales_daily')->where('company_id', $this->companyId)->whereBetween('business_date', [$from, $to])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->selectRaw('substr(business_date, 1, 7) as m, SUM(net_cents) as net, SUM(tax_cents) as tax, COUNT(DISTINCT business_date) as days')
            ->groupBy('m')->get()->keyBy('m')
            ->map(fn ($r) => ['net' => (int) $r->net, 'tax' => (int) $r->tax, 'days' => (int) $r->days])->all();
    }

    private function stores(): array
    {
        return DB::table('pingwin_locations')->where('company_id', $this->companyId)->orderBy('id')
            ->get(['id', 'display_name', 'winrest_name'])->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->display_name ?: $l->winrest_name])->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dinheiro: conta corrente, pagamentos e tesouraria
    // ─────────────────────────────────────────────────────────────────────────

    private function cash(string $ym, CarbonImmutable $start, CarbonImmutable $end, ?int $locationId): array
    {
        $cc = PingwinSupplierCcDocument::where('company_id', $this->companyId)->get();
        $auto = PingwinDocumentConfig::where('company_id', $this->companyId)->where('settled', true)->pluck('external_id')->map(fn ($v) => (string) $v)->flip();
        $paidDates = $this->paidDates($cc, $auto);
        $names = DB::table('suppliers')->where('company_id', $this->companyId)->pluck('name', 'id');

        $states = [];
        $payables = [];
        foreach ($cc as $d) {
            $isAuto = isset($auto[(string) $d->docconfig_id]);
            $state = $this->ccState($d, $isAuto, $paidDates[$d->docheader_id] ?? null);
            $states[$d->docheader_id] = $state;
            if (! $isAuto && $d->topay_cents !== 0 && ! in_array((string) $d->docconfig_id, (array) $this->cfg['payment_docconfigs'], true)) {
                $payables[] = ['doc' => $d, 'cents' => $d->topay_cents * $d->ca_signal, 'state' => $state];
            }
        }

        // Saídas com data: as notas de liquidação e as faturas-recibo (pagas no ato), do espelho dos documentos.
        $outflows = DB::table('pingwin_supplier_documents')->where('company_id', $this->companyId)
            ->where('docstatus_id', (string) $this->cfg['closed_status'])
            ->where(fn ($q) => $q->whereIn('docconfig_id', (array) $this->cfg['payment_docconfigs'])->orWhereIn('docconfig_id', $auto->keys()->filter(fn ($k) => isset($this->cfg['expense_docconfigs'][$k]))->values()->all()))
            ->whereBetween('doc_date', [$start->toDateString(), $end->toDateString()])
            ->get(['docheader_id', 'docconfig_id', 'doctype', 'document', 'entity_name', 'doc_date', 'total_cents']);
        $paidInMonth = (int) $outflows->sum('total_cents');

        $dueByEnd = 0;
        $overdue = 0;
        $total = 0;
        foreach ($payables as $p) {
            $total += $p['cents'];
            $due = $p['doc']->due_date;
            // O que vence no mês e continua por pagar (as dívidas de meses anteriores ficam no vencido).
            if ($due !== null && $due->gte($start) && $due->lte($end)) {
                $dueByEnd += $p['cents'];
            }
            if ($due !== null && $due->lt($this->today) && $p['cents'] > 0) {
                $overdue += $p['cents'];
            }
        }

        // Semanas do mês (segunda a domingo, cortadas ao mês).
        $daySales = DB::table('pingwin_item_sales_daily')->where('company_id', $this->companyId)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->selectRaw('business_date as d, SUM(net_cents + tax_cents) as gross')->groupBy('business_date')->pluck('gross', 'd');
        $weeks = [];
        for ($w = $start; $w->lte($end); $w = $w->addDays(7 - ((int) $w->format('N') - 1))) {
            $we = $w->addDays(7 - (int) $w->format('N'));
            $we = $we->gt($end) ? $end : $we;
            $in = 0;
            foreach ($daySales as $d => $g) {
                if ($d >= $w->toDateString() && $d <= $we->toDateString()) {
                    $in += (int) $g;
                }
            }
            $weeks[] = [
                'from' => $w->toDateString(), 'to' => $we->toDateString(),
                'in_cents' => $in,
                'out_cents' => (int) $outflows->filter(fn ($o) => $o->doc_date >= $w->toDateString() && $o->doc_date <= $we->toDateString())->sum('total_cents'),
                'due_cents' => array_sum(array_map(fn ($p) => $p['cents'], array_filter($payables, fn ($p) => $p['doc']->due_date !== null
                    && $p['doc']->due_date->toDateString() >= $w->toDateString() && $p['doc']->due_date->toDateString() <= $we->toDateString()))),
            ];
        }
        $overdueBefore = array_sum(array_map(fn ($p) => $p['cents'], array_filter($payables, fn ($p) => $p['doc']->due_date !== null && $p['doc']->due_date->lt($start))));

        // Os próximos 30 dias, por semana (a partir de hoje).
        $next = [];
        for ($i = 0; $i < 5; $i++) {
            $f = $this->today->addDays($i * 7);
            $t = $i === 4 ? $this->today->addDays(29) : $f->addDays(6);
            $next[] = ['from' => $f->toDateString(), 'to' => $t->toDateString(), 'cents' => array_sum(array_map(fn ($p) => $p['cents'], array_filter($payables,
                fn ($p) => $p['doc']->due_date !== null && $p['doc']->due_date->toDateString() >= $f->toDateString() && $p['doc']->due_date->toDateString() <= $t->toDateString())))];
        }

        // Lista de pagamentos do mês: as faturas do mês, as por pagar que vencem até ao fim do mês e as
        // pagas no mês; os vencidos no topo, depois pelo vencimento.
        $list = [];
        foreach ($cc as $d) {
            if (in_array((string) $d->docconfig_id, (array) $this->cfg['payment_docconfigs'], true)) {
                continue;
            }
            $s = $states[$d->docheader_id];
            $inMonth = $d->doc_date && $d->doc_date->format('Y-m') === $ym;
            $dueSoon = $s['open_cents'] !== 0 && $d->due_date !== null && $d->due_date->lte($end);
            $paidHere = $s['paid_on'] !== null && substr($s['paid_on'], 0, 7) === $ym;
            if (! $inMonth && ! $dueSoon && ! $paidHere) {
                continue;
            }
            $list[] = ['docheader_id' => $d->docheader_id, 'supplier' => $names[$d->supplier_id] ?? null, 'document' => $d->document, 'doctype' => $d->doctype,
                'doc_date' => $d->doc_date?->toDateString(), 'due_date' => $d->due_date?->toDateString(), 'total_cents' => $d->total_cents * $d->ca_signal] + $s;
        }
        usort($list, fn ($a, $b) => [$a['state'] === 'vencido' ? 0 : 1, $a['due_date'] ?? '9999', $a['document'] ?? ''] <=> [$b['state'] === 'vencido' ? 0 : 1, $b['due_date'] ?? '9999', $b['document'] ?? '']);

        $rec = $this->reconciliation($cc, $total);
        $syncedAt = $cc->max('synced_at');

        return [
            'states' => $states,
            'paid_in_month_cents' => $paidInMonth,
            'due_by_month_end_cents' => $dueByEnd,
            'overdue_cents' => $overdue,
            'cc_synced_at' => $syncedAt ? CarbonImmutable::parse($syncedAt)->format('d/m/Y') : null,
            'view' => [
                'weeks' => $weeks,
                'overdue_before_month_cents' => $overdueBefore,
                'entries_note' => 'Entradas: as vendas com IVA, assumidas como recebidas no próprio dia. A separação entre cartão e numerário ainda não existe.',
                'payments' => $list,
                'outflows' => $outflows->map(fn ($o) => ['docheader_id' => $o->docheader_id, 'document' => $o->document, 'doctype' => $o->doctype, 'supplier' => $o->entity_name,
                    'date' => $o->doc_date, 'cents' => (int) $o->total_cents])->values()->all(),
                'next_30_days' => $next,
                'overdue_cents' => $overdue,
                'total_payable_cents' => $total,
                'reconciliation' => $rec,
                'paid_date_note' => 'O PingWin não diz que faturas cada nota de liquidação paga: a data de pagamento é a da nota com o mesmo valor; sem ela, é estimada pela ordem das notas.',
                'cc_synced_at' => $syncedAt ? CarbonImmutable::parse($syncedAt)->toIso8601String() : null,
            ],
        ];
    }

    /** O estado de um documento da conta corrente: pago (em DD/MM), pago no ato, por pagar, vencido, crédito. */
    private function ccState(PingwinSupplierCcDocument $d, bool $isAuto, ?array $paid): array
    {
        $open = $isAuto ? 0 : $d->topay_cents * $d->ca_signal;
        $days = $open > 0 && $d->due_date !== null && $d->due_date->lt($this->today) ? (int) $d->due_date->diffInDays($this->today) : 0;
        [$state, $on, $estimated] = match (true) {
            $isAuto && $d->ca_signal === 1 => ['pago_no_ato', $d->doc_date?->toDateString(), false],
            $d->ca_signal === -1 && $d->topay_cents !== 0 => ['credito', null, false],
            $d->ca_signal === -1 => ['descontado', null, false],
            $d->topay_cents === 0 => ['pago', $paid['date'] ?? null, (bool) ($paid['estimated'] ?? false)],
            $days > 0 => ['vencido', null, false],
            default => ['por_pagar', null, false],
        };
        if ($state === 'por_pagar' && $d->total_paid_cents > 0) {
            $state = 'pago_em_parte';
        }

        return ['state' => $state, 'paid_on' => $on, 'paid_estimated' => $estimated, 'open_cents' => $open, 'days_overdue' => $days,
            'label' => match ($state) {
                'pago_no_ato' => 'Pago no ato a ' . self::dm($on),
                'pago' => $on ? ('Pago em ' . self::dm($on) . ($estimated ? ' (data estimada)' : '')) : 'Pago (data desconhecida)',
                'vencido' => sprintf('Vencido há %d %s', $days, $days === 1 ? 'dia' : 'dias'),
                'pago_em_parte' => $days > 0 ? sprintf('Pago em parte, vencido há %d %s', $days, $days === 1 ? 'dia' : 'dias') : 'Pago em parte',
                'credito' => 'Crédito por descontar',
                'descontado' => 'Crédito descontado',
                default => 'Por pagar',
            }];
    }

    /**
     * A data em que cada fatura paga foi paga: a nota de liquidação do mesmo fornecedor com o mesmo
     * valor (a primeira, depois da fatura); sem ela, pela ordem das notas (a mais antiga paga a mais
     * antiga), marcada como estimada.
     *
     * @return array<string, array{date: string, estimated: bool}>
     */
    private function paidDates(Collection $cc, Collection $auto): array
    {
        $pay = (array) $this->cfg['payment_docconfigs'];
        $out = [];
        foreach ($cc->groupBy('entity_pingwin_id') as $docs) {
            $nls = $docs->filter(fn ($d) => in_array((string) $d->docconfig_id, $pay, true))->sortBy(fn ($d) => $d->doc_date?->toDateString() . $d->document)->values()
                ->map(fn ($d) => ['date' => $d->doc_date?->toDateString(), 'left' => (int) $d->total_cents, 'used' => false])->all();
            $paid = $docs->filter(fn ($d) => $d->ca_signal === 1 && ! isset($auto[(string) $d->docconfig_id]) && ! in_array((string) $d->docconfig_id, $pay, true)
                && $d->topay_cents === 0 && $d->total_paid_cents > 0)->sortBy(fn ($d) => $d->doc_date?->toDateString() . $d->document)->values();
            $rest = [];
            foreach ($paid as $inv) {
                $hit = null;
                foreach ($nls as $k => $n) {
                    if (! $n['used'] && $n['left'] === (int) $inv->total_paid_cents && $n['date'] >= $inv->doc_date?->toDateString()) {
                        $hit = $k;
                        break;
                    }
                }
                if ($hit !== null) {
                    $nls[$hit]['used'] = true;
                    $nls[$hit]['left'] = 0;
                    $out[$inv->docheader_id] = ['date' => $nls[$hit]['date'], 'estimated' => false];
                } else {
                    $rest[] = $inv;
                }
            }
            foreach ($rest as $inv) {
                $need = (int) $inv->total_paid_cents;
                $date = null;
                foreach ($nls as $k => $n) {
                    if ($need <= 0) {
                        break;
                    }
                    if ($n['left'] <= 0 || $n['date'] < $inv->doc_date?->toDateString()) {
                        continue;
                    }
                    $take = min($need, $n['left']);
                    $nls[$k]['left'] -= $take;
                    $need -= $take;
                    $date = $n['date'];
                }
                if ($need <= 0 && $date !== null) {
                    $out[$inv->docheader_id] = ['date' => $date, 'estimated' => true];
                }
            }
        }

        return $out;
    }

    /**
     * Reconciliação: o por pagar deste ecrã (todos os meses) = o saldo real da conta corrente, por
     * fornecedor. Devolve os totais e os fornecedores que não batem (devia ser nenhum).
     */
    private function reconciliation(Collection $cc, int $total): array
    {
        $overview = app(SupplierCcService::class)->overview($this->companyId, $this->today);
        $auto = PingwinDocumentConfig::where('company_id', $this->companyId)->where('settled', true)->pluck('external_id')->map(fn ($v) => (string) $v)->flip();
        $mine = [];
        foreach ($cc as $d) {
            if (! isset($auto[(string) $d->docconfig_id])) {
                $mine[$d->supplier_id] = ($mine[$d->supplier_id] ?? 0) + $d->topay_cents * $d->ca_signal;
            }
        }
        $diff = [];
        $ccTotal = 0;
        foreach ($overview['suppliers'] as $s) {
            $ccTotal += $s['real_balance_cents'];
            $m = $mine[$s['supplier_id']] ?? 0;
            if ($m !== $s['real_balance_cents']) {
                $diff[] = ['supplier' => $s['name'], 'screen_cents' => $m, 'cc_cents' => $s['real_balance_cents']];
            }
        }

        return [
            'screen_total_cents' => $total, 'cc_real_balance_cents' => $ccTotal, 'ok' => $diff === [] && $total === $ccTotal,
            'mismatches' => $diff, 'pingwin_balance_cents' => $overview['cards']['pingwin_balance_cents'],
            'auto_paid_difference_cents' => $overview['cards']['difference_cents'],
            'note' => 'O por pagar é o saldo real da conta corrente: o saldo do PingWin conta também as faturas-recibo (pagas no ato) como dívida.',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Documentos
    // ─────────────────────────────────────────────────────────────────────────

    private function documentRows(Collection $docs, array $parts, array $states): array
    {
        $signs = (array) $this->cfg['expense_docconfigs'];
        $open = (array) $this->cfg['open_statuses'];
        $groups = collect($this->cfg['groups'])->map(fn ($g) => $g['label'])->all() + [FinanceClassifier::UNCLASSIFIED => 'Por classificar'];
        $ocr = DB::table('ocr_invoice_pingwin_links')->where('company_id', $this->companyId)->whereIn('docheader_id', $docs->pluck('docheader_id')->all())
            ->pluck('ocr_invoice_id', 'docheader_id');
        $rows = [];
        foreach ($docs as $d) {
            $sign = (int) ($signs[(string) $d->docconfig_id] ?? 1);
            $ps = $parts[$d->docheader_id] ?? [];
            usort($ps, fn ($a, $b) => abs($b['cents']) <=> abs($a['cents']));
            $main = $ps[0] ?? null;
            $families = array_values(array_unique(array_map(fn ($p) => FinanceClassifier::label($p['path']) ?: $groups[$p['group']], $ps)));
            $unclassified = array_sum(array_map(fn ($p) => $p['cents'], array_filter($ps, fn ($p) => $p['group'] === FinanceClassifier::UNCLASSIFIED)));
            $isOpen = in_array((string) $d->docstatus_id, $open, true);
            $st = $states[$d->docheader_id] ?? null;
            $rows[] = [
                'docheader_id' => $d->docheader_id, 'doc_date' => $d->doc_date, 'supplier' => $d->entity_name, 'entity_pingwin_id' => $d->entity_pingwin_id,
                'doctype' => $d->doctype, 'docconfig_id' => $d->docconfig_id, 'document' => $d->document, 'supplier_doc' => $d->docreference_number,
                'group' => $main['group'] ?? null, 'group_label' => $main ? $groups[$main['group']] : null,
                'family' => $families[0] ?? null, 'families' => $families,
                'net_cents' => $sign * self::net($d), 'vat_cents' => $d->total_tax_cents === null ? null : $sign * (int) $d->total_tax_cents,
                'total_cents' => $sign * (int) $d->total_cents,
                'parts' => array_map(fn ($p) => ['group' => $p['group'], 'group_label' => $groups[$p['group']], 'family' => FinanceClassifier::label($p['path']),
                    'cents' => $p['cents'], 'source' => $p['source'], 'reason' => $p['reason']], $ps),
                'unclassified_cents' => $unclassified,
                'unclassified_reason' => $unclassified !== 0 ? collect($ps)->firstWhere('group', FinanceClassifier::UNCLASSIFIED)['reason'] : null,
                'out_of_result' => $main !== null && $main['group'] === 'fora',
                'docstatus_id' => $d->docstatus_id, 'docstatus' => $d->docstatus_description, 'open' => $isOpen,
                'due_date' => $d->due_date,
                'payment' => $st ?? ['state' => 'fora_cc', 'label' => $d->paid ? 'Pago (fora da conta corrente sincronizada)' : 'Ainda não está na conta corrente sincronizada',
                    'paid_on' => null, 'paid_estimated' => false, 'open_cents' => 0, 'days_overdue' => 0],
                'ocr_invoice_id' => isset($ocr[$d->docheader_id]) ? (int) $ocr[$d->docheader_id] : null,
            ];
        }
        usort($rows, fn ($a, $b) => [$a['unclassified_cents'] !== 0 ? 0 : 1, $a['open'] ? 0 : 1, $a['doc_date'], $a['document']]
            <=> [$b['unclassified_cents'] !== 0 ? 0 : 1, $b['open'] ? 0 : 1, $b['doc_date'], $b['document']]);

        return $rows;
    }

    private static function dm(?string $date): string
    {
        return $date ? substr($date, 8, 2) . '/' . substr($date, 5, 2) : '—';
    }
}
