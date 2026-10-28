<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GeneralExpenseSupplier;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\PingwinCatalogItem;
use Illuminate\Support\Collection;

/**
 * Faturas de despesas gerais (fornecedores de serviços, como os CTT). Com o fornecedor marcado:
 * - as linhas com valor ligam-se sozinhas ao artigo de despesa da sua taxa de IVA e, ao lançar,
 *   juntam-se numa linha por taxa (como um artigo de agrupamento: quantidade 1, o valor somado), por
 *   isso a falta de quantidade não bloqueia;
 * - as linhas a 0,00 € (por exemplo o código de seguimento de uma carta registada) não contam e não
 *   precisam de ligação;
 * - se faltar o artigo de uma taxa, essas linhas ficam por ligar como hoje, com o aviso da taxa.
 * Um fornecedor sem marca fica exatamente como hoje. Nada se cria nem se muda no PingWin.
 */
class GeneralExpenseService
{
    public const METHOD = 'despesa';

    /** As taxas que se podem configurar (as dos grupos de IVA do PingWin). */
    public const RATES = [23, 13, 6, 0];

    /** A definição do fornecedor da fatura, se estiver marcado. */
    public function forInvoice(OcrInvoice $inv): ?GeneralExpenseSupplier
    {
        $supplier = app(OcrLineArticleService::class)->supplier($inv);

        return $supplier ? GeneralExpenseSupplier::where('company_id', $inv->company_id)->where('supplier_id', $supplier->id)->first() : null;
    }

    /** A linha tem valor (as de 0,00 € não contam numa fatura de despesas gerais). */
    public static function hasValue(OcrInvoiceLine $line): bool
    {
        return (int) $line->line_total_cents !== 0;
    }

    /**
     * Liga as linhas com valor ao artigo da sua taxa. $force ("Aplicar a esta fatura") também muda as
     * ligadas à mão; sem ele (ao ler ou reabrir a fatura), as manuais ficam.
     *
     * @return array{count: array<string, int>, summary: array}
     */
    public function apply(OcrInvoice $inv, GeneralExpenseSupplier $setting, bool $force): array
    {
        $usable = $this->usableArticles($setting);
        $count = ['mapa' => 0, 'pingwin' => 0, 'descricao' => 0, 'sugerida' => 0, 'por_ligar' => 0, 'manual' => 0, self::METHOD => 0, 'sem_valor' => 0];
        foreach ($inv->lines()->orderBy('position')->get() as $line) {
            if (! $force && $line->isManual() && $line->article_id) {
                $count['manual']++;
                continue;
            }
            $articleId = $setting->articleFor($line->vat_rate === null ? null : (int) $line->vat_rate);
            if (! self::hasValue($line)) {
                $count['sem_valor']++;
                $line->fill($this->unlinked())->save(); // não conta e não precisa de ligação
            } elseif ($articleId !== null && isset($usable[$articleId])) {
                $count[self::METHOD]++;
                $line->fill(['article_id' => $articleId, 'link_state' => OcrInvoiceLine::LINKED, 'link_method' => self::METHOD, 'link_confidence' => 1.0,
                    'link_suggestions' => null, 'linked_by' => null, 'linked_at' => now()])->save();
            } else {
                $count['por_ligar']++;
                // Falta o artigo desta taxa: fica por ligar como hoje (se estava ligada pela despesa, sai).
                if ($force || $line->link_method === self::METHOD) {
                    $line->fill($this->unlinked())->save();
                }
            }
        }

        return ['count' => $count, 'summary' => $this->summary($inv->fresh('lines'), $setting)];
    }

    /**
     * O que o ecrã e o lançamento mostram: as linhas com valor, as taxas (com o artigo de cada uma) e
     * as que faltam, e quantas a 0,00 € foram ignoradas.
     */
    public function summary(OcrInvoice $inv, GeneralExpenseSupplier $setting): array
    {
        $lines = $inv->lines;
        $withValue = $lines->filter(fn ($l) => self::hasValue($l));
        $usable = $this->usableArticles($setting);
        $rates = $withValue->groupBy(fn ($l) => $l->vat_rate === null ? 'sem' : (string) (int) $l->vat_rate);
        $perRate = [];
        $missing = [];
        foreach ($rates as $rate => $group) {
            $id = $rate === 'sem' ? null : $setting->articleFor((int) $rate);
            $article = $id !== null ? ($usable[$id] ?? null) : null;
            $perRate[] = ['rate' => $rate === 'sem' ? null : (int) $rate, 'lines' => $group->count(),
                'total_cents' => (int) $group->sum('line_total_cents'), 'article' => $article];
            if ($article === null) {
                $missing[] = $rate === 'sem' ? null : (int) $rate;
            }
        }
        usort($perRate, fn ($a, $b) => ($b['rate'] ?? -1) <=> ($a['rate'] ?? -1));

        return ['supplier_id' => $setting->supplier_id, 'lines' => $withValue->count(), 'ignored_zero' => $lines->count() - $withValue->count(),
            'rates' => $perRate, 'missing_rates' => $missing];
    }

    /** Os artigos de despesa deste fornecedor que se podem usar (ativos): id → {id, code, description}. */
    public function usableArticles(GeneralExpenseSupplier $setting): array
    {
        $ids = array_values(array_filter(array_map('intval', array_values($setting->articles ?? []))));
        if ($ids === []) {
            return [];
        }

        return OcrLineArticleService::usable(PingwinCatalogItem::where('company_id', $setting->company_id)->whereIn('id', $ids))
            ->get(['id', 'code', 'description'])
            ->mapWithKeys(fn ($a) => [(int) $a->id => ['id' => (int) $a->id, 'code' => $a->code, 'description' => $a->description]])->all();
    }

    /** Os artigos de despesa (id do catálogo → true), para juntarem as linhas como no agrupamento. */
    public function groupingMap(GeneralExpenseSupplier $setting): array
    {
        return array_map(fn () => true, $this->usableArticles($setting));
    }

    /** Só as linhas com valor (as de 0,00 € não vão para o PingWin). */
    public static function withValue(Collection $lines): Collection
    {
        return $lines->filter(fn ($l) => self::hasValue($l))->values();
    }

    private function unlinked(): array
    {
        return ['article_id' => null, 'link_state' => OcrInvoiceLine::UNLINKED, 'link_method' => null, 'link_confidence' => null,
            'link_suggestions' => null, 'linked_by' => null, 'linked_at' => null];
    }
}
