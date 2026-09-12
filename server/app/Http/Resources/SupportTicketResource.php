<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DMS — shape do ticket de suporte. Allow-list. A thread (messages) só vai
 * quando eager-loaded (na vista de detalhe).
 */
class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'company_id'     => $this->company_id,
            // company_name só é emitido quando a empresa está eager-loaded
            // (lado admin, que mostra tickets de várias empresas).
            'company_name'   => $this->whenLoaded('company', fn () => $this->company?->fiscal_name),
            'type'           => $this->type,
            'title'          => $this->title,
            'description'    => $this->description,
            'status'         => $this->status,
            'screenshot_url' => $this->screenshot_path,
            'resolved_at'    => optional($this->resolved_at)->toIso8601String(),
            'author_name'    => $this->whenLoaded('user', fn () => $this->user?->name),
            'messages_count' => $this->whenCounted('messages'),
            'messages'       => SupportTicketMessageResource::collection($this->whenLoaded('messages')),
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
