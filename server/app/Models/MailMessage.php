<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E-mail 2 · Uma mensagem guardada na XPLENDOR: enviada por uma caixa de correio (direction out) ou,
 * no E-mail 4, uma resposta ligada a um cliente (in). O Message-ID é gerado antes do envio, para o
 * fio das respostas.
 */
class MailMessage extends Model
{
    public const OUT = 'out';
    public const IN = 'in';

    public const STATUS_QUEUED = 'a_enviar';
    public const STATUS_SENT = 'enviada';
    public const STATUS_ERROR = 'erro';

    protected $fillable = [
        'company_id', 'mailbox_id', 'user_id', 'direction', 'message_id', 'in_reply_to', 'references', 'from_address', 'to_address',
        'subject', 'body_text', 'body_html', 'status', 'error', 'sent_at', 'received_at', 'customer_id', 'car_sale_id', 'car_lead_id',
        'satisfaction_report_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
