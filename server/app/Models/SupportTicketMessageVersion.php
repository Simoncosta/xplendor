<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O texto de uma mensagem de um pedido de suporte ANTES de uma edição (só o root o vê). */
class SupportTicketMessageVersion extends Model
{
    protected $fillable = ['support_ticket_message_id', 'body', 'edited_by_user_id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }
}
