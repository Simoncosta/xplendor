<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DMS — shape do Cliente. Allow-list. Emite nomes de localidade (whenLoaded) e
 * campos server-computed (archived, sales_count, can_delete).
 */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $salesCount = (int) ($this->sales_count ?? 0);

        return [
            'id'                    => $this->id,
            'company_id'            => $this->company_id,
            'name'                  => $this->name,
            'nif'                   => $this->nif,
            'phone'                 => $this->phone,
            'email'                 => $this->email,

            'address'               => $this->address,
            'postal_code'           => $this->postal_code,
            'district_id'           => $this->district_id,
            'municipality_id'       => $this->municipality_id,
            'parish_id'             => $this->parish_id,
            'district_name'         => $this->whenLoaded('district', fn () => $this->district?->name),
            'municipality_name'     => $this->whenLoaded('municipality', fn () => $this->municipality?->name),
            'parish_name'           => $this->whenLoaded('parish', fn () => $this->parish?->name),

            'citizen_card_number'   => $this->citizen_card_number,
            'citizen_card_validity' => optional($this->citizen_card_validity)->toDateString(),
            'birth_date'            => optional($this->birth_date)->toDateString(),
            'nationality'           => $this->nationality,
            'profession'            => $this->profession,
            'marital_status'        => $this->marital_status,

            'contact_consent'       => (bool) $this->contact_consent,
            'notes'                 => $this->notes,
            'archived'              => (bool) $this->archived,
            'sales_count'           => $salesCount,
            'can_delete'            => $salesCount === 0,

            'created_at'            => $this->created_at,
            'updated_at'            => $this->updated_at,
        ];
    }
}
