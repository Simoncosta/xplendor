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
            // Camada de orçamento — só relevante em tickets 'site_change' (null nos grátis).
            'quote_status'    => $this->quote_status,
            'estimated_hours' => $this->estimated_hours !== null ? (float) $this->estimated_hours : null,
            'quoted_amount'   => $this->quoted_amount !== null ? (float) $this->quoted_amount : null,
            'invoice_url'     => $this->invoice_path,
            'hourly_rate'     => $this->type === 'site_change' ? (float) config('tickets.site_change_hourly_rate') : null,
            'author_name'    => $this->whenLoaded('user', fn () => $this->user?->name),
            'messages_count' => $this->whenCounted('messages'),
            'messages'       => SupportTicketMessageResource::collection($this->whenLoaded('messages')),
            // Histórico das mudanças de tipo: só o lado admin o carrega (o cliente recebe a mensagem na thread).
            'type_changes'   => $this->whenLoaded('typeChanges', fn () => $this->typeChanges->map(fn ($c) => [
                'id'                       => $c->id,
                'from_type'                => $c->from_type,
                'to_type'                  => $c->to_type,
                'previous_quote_status'    => $c->previous_quote_status,
                'previous_quoted_amount'   => $c->previous_quoted_amount !== null ? (float) $c->previous_quoted_amount : null,
                'previous_estimated_hours' => $c->previous_estimated_hours !== null ? (float) $c->previous_estimated_hours : null,
                'changed_by_name'          => $c->changedBy?->name,
                'created_at'               => optional($c->created_at)->toIso8601String(),
            ])->values()->all()),
            // Lista de tarefas (ticket de arranque de um orçamento aceite).
            'tasks'          => $this->whenLoaded('tasks', fn () => $this->tasks->map(fn ($t) => [
                'id'           => $t->id,
                'group_label'  => $t->group_label,
                'title'        => $t->title,
                'done'         => $t->done_at !== null,
                'done_at'      => optional($t->done_at)->toIso8601String(),
                'done_by_name' => $t->doneBy?->name,
            ])->values()->all()),
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
