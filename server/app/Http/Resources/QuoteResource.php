<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * XPLENDOR — shape do orçamento avulso (allow-list). company_name só quando a
 * empresa está eager-loaded (ligação opcional, futura).
 */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'company_id'     => $this->company_id,
            'company_name'   => $this->whenLoaded('company', fn () => $this->company?->fiscal_name),
            'client_name'    => $this->client_name,
            'client_contact' => $this->client_contact,
            'description'    => $this->description,
            'amount'         => $this->amount !== null ? (float) $this->amount : null,
            'status'         => $this->status,
            'notes'          => $this->notes,
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
