<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DMS — mensagem da thread. is_staff distingue super-admin (true) do stand.
 */
class SupportTicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            // Sem a faturação da XPLENDOR, os valores em euros das mensagens da equipa ficam de fora.
            'body'        => $this->is_staff && $request->attributes->get('support_billing_visible') === false
                ? \App\Support\Text\MoneyText::redact($this->body)
                : $this->body,
            'is_staff'    => (bool) $this->is_staff,
            'author_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'created_at'  => optional($this->created_at)->toIso8601String(),
        ];
    }
}
