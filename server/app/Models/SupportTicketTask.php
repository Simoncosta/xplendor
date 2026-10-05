<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tarefa de um ticket (lista de arranque copiada do catálogo de serviços). */
class SupportTicketTask extends Model
{
    protected $fillable = ['support_ticket_id', 'position', 'group_label', 'title', 'catalog_item_id', 'done_at', 'done_by_user_id'];

    protected $casts = ['done_at' => 'datetime', 'position' => 'integer'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by_user_id');
    }
}
