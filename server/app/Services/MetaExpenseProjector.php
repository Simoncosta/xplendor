<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Car;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MetaAd;
use App\Repositories\CarAdSpendRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Despesas automáticas do gasto Meta (projeção mensal).
 *
 * O CarAdSpendRepository continua a ser a fonte de verdade; as despesas são uma
 * PROJEÇÃO dele, uma linha por mês:
 *   · por viatura   → meta:car:{N}:{AAAA-MM}   (ligada à viatura; N = o da tag, por
 *                     isso uma viatura apagada mantém a linha, sem viatura);
 *   · stock geral   → meta:general:{AAAA-MM}   (anúncios sem tag, sem viatura);
 *   · por atribuir  → meta:unattributed:{AAAA-MM} (anúncios com tag inválida).
 *
 * Idempotente: corre de novo e dá o mesmo resultado (upsert por source_key); a
 * Meta revê os últimos dias e a linha acompanha; um mês que fique a zero perde a
 * linha automática. Valores como a Meta os reporta (sem IVA).
 *
 * Regra da dupla contagem (decisão do utilizador, opção 1): as automáticas são a
 * repartição analítica. Contam na margem por viatura e no dashboard de margem; nos
 * totais da página de Despesas aparecem à parte, como informativas (ver
 * ExpenseController::summary). A fatura manual da Meta é o registo financeiro.
 */
class MetaExpenseProjector
{
    public const CATEGORY_NAME = 'Publicidade Meta';
    public const CATEGORY_COLOR = '#1877F2';

    private const MONTHS = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
        7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    public function __construct(private readonly CarAdSpendRepository $spend) {}

    /**
     * Os últimos $n meses (o atual incluído), do mais antigo para o mais recente.
     *
     * @return list<string> AAAA-MM
     */
    public static function recentMonths(int $n, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfMonth();
        $months = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $months[] = $today->subMonths($i)->format('Y-m');
        }

        return $months;
    }

    /**
     * Meses (AAAA-MM) que cobrem [since, until].
     *
     * @return list<string>
     */
    public static function monthsBetween(string $since, string $until): array
    {
        $months = [];
        $cursor = CarbonImmutable::parse($since)->startOfMonth();
        $end = CarbonImmutable::parse($until)->startOfMonth();
        while ($cursor->lte($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /**
     * Projeta os meses pedidos. Devolve um resumo por mês (linhas escritas/apagadas).
     *
     * @param  list<string>  $months  AAAA-MM
     */
    public function project(int $companyId, array $months): array
    {
        $summary = [];
        foreach ($months as $month) {
            $summary[$month] = DB::transaction(fn () => $this->projectMonth($companyId, $month));
        }

        return $summary;
    }

    private function projectMonth(int $companyId, string $month): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $from = $start->toDateString();
        $to = $start->endOfMonth()->toDateString();
        $label = self::MONTHS[(int) $start->month] . ' ' . $start->year;
        $vat = 'valor reportado pela Meta, sem IVA';

        $rows = [];

        // 1. Por viatura (tag ou mapeamento manual, fonte única).
        $byCar = array_filter($this->spend->spendByCarKey($companyId, $from, $to), fn (array $r) => $r['spend'] > 0);
        $cars = $byCar === [] ? collect() : Car::where('company_id', $companyId)
            ->whereKey(array_keys($byCar))
            ->with(['brand:id,name', 'model:id,name'])
            ->get()
            ->keyBy('id');

        foreach ($byCar as $carKey => $r) {
            $car = $cars->get($carKey);
            $notes = [];
            if ($r['tag_spend'] > 0) {
                $notes[] = 'Por etiqueta [id:N] nos anúncios: ' . self::money($r['tag_spend']) . '.';
            }
            if ($r['legacy_spend'] > 0) {
                $notes[] = 'Por mapeamento manual de campanha: ' . self::money($r['legacy_spend']) . '.';
            }
            if ($r['split_spend'] > 0) {
                $notes[] = 'Parte repartida em partes iguais com outras viaturas do mesmo anúncio: ' . self::money($r['split_spend']) . '.';
            }
            if ($car && $car->status === 'sold' && $car->sold_at) {
                $soldDate = CarbonImmutable::parse($car->sold_at)->toDateString();
                $post = round((float) $this->spend->dailyRows($companyId, $from, $to, [$car->id])
                    ->where('date', '>', $soldDate)->sum('spend'), 2);
                if ($post > 0) {
                    $notes[] = sprintf('Inclui %s gastos depois da venda (%s).', self::money($post), CarbonImmutable::parse($soldDate)->format('d/m/Y'));
                }
            }

            $rows["meta:car:{$carKey}:{$month}"] = [
                'car_id' => $car?->id,
                'amount' => $r['spend'],
                'description' => $car
                    ? "Meta Ads, {$label} ({$vat})"
                    : "Meta Ads, {$label}, viatura removida n.º {$carKey} ({$vat})",
                'notes' => implode(' ', $notes) ?: null,
            ];
        }

        // 2. Stock geral (sem tag) e 3. por atribuir (tag inválida): só com ingestão por anúncio.
        if ($this->spend->hasAdLevelData($companyId)) {
            $general = $this->spend->adLevelSpendByTagStatus($companyId, $from, $to, [MetaAd::TAG_UNTAGGED]);
            if ($general > 0) {
                $rows["meta:general:{$month}"] = [
                    'car_id' => null,
                    'amount' => $general,
                    'description' => "Meta Ads, {$label}, stock geral ({$vat})",
                    'notes' => 'Anúncios sem etiqueta de viatura [id:N]: despesa da empresa, sem viatura.',
                ];
            }

            $unattributed = $this->spend->adLevelSpendByTagStatus($companyId, $from, $to, [MetaAd::TAG_INVALID]);
            if ($unattributed > 0) {
                $rows["meta:unattributed:{$month}"] = [
                    'car_id' => null,
                    'amount' => $unattributed,
                    'description' => "Meta Ads, {$label}, por atribuir ({$vat})",
                    'notes' => 'Anúncios com etiqueta [id:N] inválida (viatura inexistente ou de outra empresa). Ver os avisos de qualidade de dados.',
                ];
            }
        }

        // Upsert por source_key.
        $now = now();
        $written = 0;
        $categoryId = $rows === [] ? null : $this->categoryId($companyId);
        foreach ($rows as $key => $row) {
            // source/source_key não são preenchíveis em massa (de propósito): forceFill.
            $expense = Expense::where('company_id', $companyId)->where('source_key', $key)->first() ?? new Expense();
            $expense->forceFill([
                'company_id' => $companyId,
                'source_key' => $key,
                'source' => Expense::SOURCE_META_ADS,
                'description' => mb_substr($row['description'], 0, 255),
                'amount' => $row['amount'],
                'date' => $to,
                'expense_category_id' => $categoryId,
                'supplier_id' => null,
                'car_id' => $row['car_id'],
                // A Meta cobra automaticamente: nunca fica "em aberto".
                'is_paid' => true,
                'paid_at' => $to,
                'archived' => false,
                'notes' => $row['notes'],
            ]);
            if (! $expense->exists || $expense->isDirty()) {
                $expense->forceFill(['source_synced_at' => $now])->save();
                $written++;
            }
        }

        // Linhas automáticas deste mês que já não têm gasto → saem.
        $deleted = Expense::where('company_id', $companyId)
            ->where('source', Expense::SOURCE_META_ADS)
            ->where('source_key', 'like', 'meta:%:' . $month)
            ->when($rows !== [], fn ($q) => $q->whereNotIn('source_key', array_keys($rows)))
            ->get()
            ->each->delete()
            ->count();

        return ['lines' => count($rows), 'written' => $written, 'deleted' => $deleted];
    }

    /** Categoria de sistema "Publicidade Meta" da empresa (criada uma vez). */
    private function categoryId(int $companyId): int
    {
        $category = ExpenseCategory::where('company_id', $companyId)
            ->where('system_key', ExpenseCategory::SYSTEM_META_ADS)
            ->first();

        if (! $category) {
            $category = new ExpenseCategory();
            $category->forceFill([
                'company_id' => $companyId,
                'system_key' => ExpenseCategory::SYSTEM_META_ADS,
                'name' => self::CATEGORY_NAME,
                'color' => self::CATEGORY_COLOR,
                'archived' => false,
            ])->save();
        }

        return (int) $category->id;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ') . ' €';
    }
}
