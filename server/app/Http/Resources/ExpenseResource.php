<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DMS sub-fase 1c.2b — shape da Despesa. Allow-list.
 * Emite os nomes de categoria/fornecedor/viatura quando eager-loaded, e
 * `can_delete` (só se NÃO tiver vínculo — senão a UI arquiva).
 */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasLinks = $this->supplier_id !== null
            || $this->car_id !== null
            || $this->expense_category_id !== null;

        return [
            'id'                  => $this->id,
            'company_id'          => $this->company_id,
            'description'         => $this->description,
            'amount'              => (float) $this->amount,
            'date'                => optional($this->date)->toDateString(),

            'expense_category_id' => $this->expense_category_id,
            'supplier_id'         => $this->supplier_id,
            'car_id'              => $this->car_id,
            'category_name'       => $this->whenLoaded('category', fn () => $this->category?->name),
            'category_color'      => $this->whenLoaded('category', fn () => $this->category?->color),
            'supplier_name'       => $this->whenLoaded('supplier', fn () => $this->supplier?->name),
            'car_name'            => $this->whenLoaded('car', fn () => trim(($this->car?->brand?->name ?? '') . ' ' . ($this->car?->model?->name ?? '')) ?: ('#' . $this->car_id)),

            'is_paid'             => (bool) $this->is_paid,
            'paid_at'             => optional($this->paid_at)->toDateString(),
            'archived'            => (bool) $this->archived,
            'can_delete'          => ! $hasLinks,
            'notes'               => $this->notes,

            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }
}
