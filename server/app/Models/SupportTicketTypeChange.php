<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma mudança de tipo de um ticket: tipo anterior e novo e, se havia, o orçamento
 * anterior (estado, valor, horas). Só se cria; nunca se edita.
 */
class SupportTicketTypeChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'support_ticket_id',
        'company_id',
        'from_type',
        'to_type',
        'previous_quote_status',
        'previous_quoted_amount',
        'previous_estimated_hours',
        'changed_by_user_id',
    ];

    protected $casts = [
        'previous_quoted_amount'   => 'decimal:2',
        'previous_estimated_hours' => 'decimal:2',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
