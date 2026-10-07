<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tarefa de um ticket (lista de arranque copiada do catálogo de serviços). */
class SupportTicketTask extends Model
{
    /** Chaves fixas: a tarefa marca-se sozinha quando o passo correspondente fica feito (nunca pelo texto). */
    public const KEY_SOCIAL_ACCESS = 'social_access';
    public const KEY_META_ADS_ACCESS = 'meta_ads_access';
    public const KEY_GA4_ACCESS = 'ga4_access';
    public const KEYS = [
        self::KEY_SOCIAL_ACCESS => 'Acesso às redes sociais',
        self::KEY_META_ADS_ACCESS => 'Acesso à conta de anúncios da Meta',
        self::KEY_GA4_ACCESS => 'Acesso ao Google Analytics',
    ];

    protected $fillable = ['support_ticket_id', 'position', 'group_label', 'title', 'catalog_item_id', 'task_key', 'done_at', 'done_by_user_id'];

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
