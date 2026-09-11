<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DMS sub-fase 1c.1 — shape do Fornecedor. Allow-list explícita.
 * Emite os nomes de distrito/município/freguesia quando eager-loaded (para a
 * listagem mostrar a morada legível sem o frontend ter de resolver os IDs).
 */
class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'company_id'      => $this->company_id,
            'name'            => $this->name,
            'nif'             => $this->nif,
            'phone'           => $this->phone,
            'email'           => $this->email,

            'address'         => $this->address,
            'postal_code'     => $this->postal_code,
            'district_id'     => $this->district_id,
            'municipality_id' => $this->municipality_id,
            'parish_id'       => $this->parish_id,
            'district_name'     => $this->whenLoaded('district', fn () => $this->district?->name),
            'municipality_name' => $this->whenLoaded('municipality', fn () => $this->municipality?->name),
            'parish_name'       => $this->whenLoaded('parish', fn () => $this->parish?->name),

            'iban'            => $this->iban,
            'notes'           => $this->notes,

            // 1c.2b — arquivo. `can_delete` só quando não tem despesas associadas.
            'archived'        => (bool) $this->archived,
            'expenses_count'  => (int) ($this->expenses_count ?? 0),
            'can_delete'      => ((int) ($this->expenses_count ?? 0)) === 0,

            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
