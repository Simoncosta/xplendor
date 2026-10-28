<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um fornecedor de despesas gerais (sem stock), por empresa: o artigo de despesa de cada taxa de IVA
 * (articles: taxa → id do catálogo). As faturas dele lançam-se com uma linha por taxa.
 */
class GeneralExpenseSupplier extends Model
{
    protected $fillable = ['company_id', 'supplier_id', 'articles', 'changed_by'];

    protected $casts = ['articles' => 'array'];

    /** O artigo (id do catálogo) da taxa, ou null. */
    public function articleFor(?int $rate): ?int
    {
        if ($rate === null) {
            return null;
        }
        $id = ($this->articles ?? [])[(string) $rate] ?? null;

        return $id ? (int) $id : null;
    }
}
