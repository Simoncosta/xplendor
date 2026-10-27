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
    public const STATUS_RECEIVED = 'recebida'; // E-mail 4: uma resposta ligada pelo fio, ou ligada à mão

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

    /** E-mail 4 · O que a secção "Conversa" mostra (sem ids internos de outras tabelas). */
    public function toConversationArray(): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'from' => $this->from_address,
            'to' => $this->to_address,
            'subject' => $this->subject,
            'date' => ($this->direction === self::IN ? ($this->received_at ?? $this->created_at) : ($this->sent_at ?? $this->created_at))?->toIso8601String(),
            'text' => $this->body_text,
            'html' => $this->body_html,
            'status' => $this->status,
        ];
    }
}
