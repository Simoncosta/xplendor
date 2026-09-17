<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * XPLENDOR — shape da tarefa interna do cliente (allow-list). Nomes do
 * responsável/criador só quando eager-loaded.
 */
class CompanyTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'company_id'       => $this->company_id,
            'title'            => $this->title,
            'description'      => $this->description,
            'status'           => $this->status,
            'order'            => (int) $this->order,
            'assignee_user_id' => $this->assignee_user_id,
            'assignee_name'    => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'created_by'       => $this->created_by,
            'creator_name'     => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at'       => optional($this->created_at)->toIso8601String(),
            'updated_at'       => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
