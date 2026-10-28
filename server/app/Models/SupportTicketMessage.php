<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DMS — Mensagem da thread de um ticket de suporte. A apagada continua na thread (deleted_at, com
 * a marca para a cliente); o texto anterior de uma edição fica nas versões, só para o root.
 */
class SupportTicketMessage extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'user_id',
        'body',
        'is_staff',
        'impersonator_user_id',
        'edited_at',
        'deleted_at',
        'deleted_by_user_id',
    ];

    protected $casts = [
        'is_staff'   => 'boolean',
        'edited_at'  => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** O root que escreveu na sessão como cliente (a mensagem conta como da equipa). */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_user_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SupportTicketMessageVersion::class)->orderByDesc('id');
    }
}
