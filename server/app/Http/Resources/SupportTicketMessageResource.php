<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DMS — mensagem da thread. is_staff distingue super-admin (true) do stand. A mensagem escrita pelo
 * root na sessão como cliente conta como da equipa e mostra o nome dele. Apagada: a cliente vê só a
 * marca e a data (sem o texto); editada: a marca e a data. O texto anterior e o apagado só a equipa.
 */
class SupportTicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $team = (bool) $request->user()?->isRoot();
        $deleted = $this->deleted_at !== null;
        // Sem a faturação da XPLENDOR, os valores em euros das mensagens da equipa ficam de fora.
        $body = $this->is_staff && $request->attributes->get('support_billing_visible') === false
            ? \App\Support\Text\MoneyText::redact($this->body)
            : $this->body;

        return [
            'id'          => $this->id,
            'body'        => $deleted && ! $team ? null : $body,
            'is_staff'    => (bool) $this->is_staff,
            'author_name' => $this->impersonator_user_id
                ? $this->whenLoaded('impersonator', fn () => $this->impersonator?->name)
                : $this->whenLoaded('user', fn () => $this->user?->name),
            'edited_at'   => optional($this->edited_at)->toIso8601String(),
            'deleted'     => $deleted,
            'deleted_at'  => optional($this->deleted_at)->toIso8601String(),
            'created_at'  => optional($this->created_at)->toIso8601String(),
            // Só a equipa: o texto antes de cada edição, se foi escrita na sessão como cliente, e se a pode editar.
            'team'        => $this->when($team, fn () => [
                'impersonated' => $this->impersonator_user_id !== null,
                'can_edit'     => ! $deleted && app(\App\Services\SupportTickets\TicketAuthorship::class)->ownsMessage($request->user(), $this->resource),
                'versions'     => $this->relationLoaded('versions') ? $this->versions->map(fn ($v) => [
                    'body'           => $v->body,
                    'edited_by_name' => $v->editedBy?->name,
                    'created_at'     => optional($v->created_at)->toIso8601String(),
                ])->values()->all() : [],
            ]),
        ];
    }
}
