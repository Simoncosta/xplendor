<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DMS sub-fase 1c.2a — shape da Categoria de Despesa. Allow-list.
 *
 * `expenses_count` / `can_delete`: nesta sub-fase valem 0 / true (não há despesas).
 * O controller injecta `expenses_count` quando a relação existir (1c.2b via
 * withCount); até lá o default 0 mantém a UI correcta (tudo eliminável).
 */
class ExpenseCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expensesCount = (int) ($this->expenses_count ?? 0);

        return [
            'id'             => $this->id,
            'company_id'     => $this->company_id,
            'name'           => $this->name,
            'color'          => $this->color,
            'archived'       => (bool) $this->archived,
            'expenses_count' => $expensesCount,
            'can_delete'     => $expensesCount === 0,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
