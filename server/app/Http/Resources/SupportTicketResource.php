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
        // O orçamento (valor, horas, fatura) é da faturação da XPLENDOR: só o Administrador o vê.
        $billing = \App\Access\XplendorBilling::visibleTo($request, (int) $this->company_id);
        $request->attributes->set('support_billing_visible', $billing);

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
            // Camada de orçamento — só relevante em tickets 'site_change' (null nos grátis). O estado
            // vai para todos; o valor, as horas e a fatura só para quem vê a faturação da XPLENDOR.
            'quote_status'    => $this->quote_status,
            'billing_visible' => $billing,
            'estimated_hours' => $billing && $this->estimated_hours !== null ? (float) $this->estimated_hours : null,
            'quoted_amount'   => $billing && $this->quoted_amount !== null ? (float) $this->quoted_amount : null,
            // Disco privado: URL assinado de curta duração (esta resposta já passou pela empresa e pelo ACL).
            'invoice_url'     => $billing ? \App\Support\Storage\PrivateFiles::ticketInvoiceUrl($this->resource) : null,
            'hourly_rate'     => $billing && $this->type === 'site_change' ? (float) config('tickets.site_change_hourly_rate') : null,
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
                'task_key'     => $t->task_key,
                'done'         => $t->done_at !== null,
                'done_at'      => optional($t->done_at)->toIso8601String(),
                'done_by_name' => $t->doneBy?->name,
            ])->values()->all()),
            // Ticket de arranque: a empresa a configurar pelo link (a do orçamento; num orçamento de
            // prospeto o ticket está na XPLENDOR e a empresa escolhe-se no ecrã).
            'setup_company_id' => $this->when($this->type === \App\Models\SupportTicket::TYPE_ONBOARDING, function () {
                $fromQuote = \App\Models\Quote::where('onboarding_ticket_id', $this->id)->value('company_id');

                return $fromQuote ?: ((int) $this->company_id !== (int) \App\Services\Billing\ChargeService::teamCompanyId() ? $this->company_id : null);
            }),
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
