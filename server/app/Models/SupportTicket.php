<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * DMS — Ticket de suporte (scoped por company).
 */
class SupportTicket extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    // 'site_change' é o tipo PAGO (dispara a camada de orçamento). Os restantes
    // são grátis/suporte normal.
    public const TYPES = ['idea', 'improvement', 'bug', 'suggestion', 'site_change'];
    public const STATUSES = ['open', 'in_review', 'resolved', 'closed'];

    // Tipo de SISTEMA (nunca escolhido pelo cliente nem na reclassificação): o ticket de
    // arranque criado quando um orçamento é aceite, com a lista de tarefas dos serviços.
    public const TYPE_ONBOARDING = 'onboarding';

    // Fluxo de orçamento — só para tickets 'site_change' (null nos grátis). Só a aprovação e o
    // trabalho: a fatura (invoiced_at) e o pagamento (paid_at) têm colunas próprias. 'paid' é o
    // valor antigo (antes da separação), já migrado para 'approved' com paid_at; fica na lista
    // para os cartões do pipeline continuarem a ter a chave.
    public const QUOTE_STATUSES = ['awaiting_quote', 'quoted', 'approved', 'paid', 'completed', 'rejected'];
    public const LEGACY_PAID = 'paid';

    // A partir daqui há trabalho aprovado: anexar a fatura, marcar pago e concluir.
    public const QUOTE_WORK_STATUSES = ['approved', 'paid', 'completed'];

    // Mudança de tipo: com orçamento nestes estados é bloqueada (há dinheiro aprovado), tal como
    // com fatura ou pagamento; nestes, é permitida com confirmação e o orçamento fica a null.
    public const QUOTE_LOCKED_STATUSES = ['approved', 'paid', 'completed'];
    public const QUOTE_RESETTABLE_STATUSES = ['quoted', 'rejected'];

    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'title',
        'description',
        'status',
        'screenshot_path',
        'resolved_at',
        'estimated_hours',
        'quoted_amount',
        'invoice_path',
        'quote_status',
        'impersonator_user_id',
        'invoiced_at',
        'invoice_by_user_id',
        'paid_at',
        'paid_by_user_id',
        'deleted_by_user_id',
    ];

    protected $casts = [
        'resolved_at'     => 'datetime',
        'invoiced_at'     => 'datetime',
        'paid_at'         => 'datetime',
        'estimated_hours' => 'decimal:2',
        'quoted_amount'   => 'decimal:2',
    ];

    /** Rótulos dos tipos (iguais aos do ecrã), para mensagens ao cliente. */
    public const TYPE_LABELS = [
        'idea'        => 'Ideia',
        'improvement' => 'Melhoria',
        'bug'         => 'Bug',
        'suggestion'  => 'Sugestão',
        'site_change' => 'Alteração ao site',
        'onboarding'  => 'Arranque',
    ];

    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    /** Este ticket é do tipo pago (com camada de orçamento)? */
    public function isSiteChange(): bool
    {
        return $this->type === 'site_change';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** O root que criou o ticket na sessão como cliente (o ticket continua a ser da empresa). */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_user_id');
    }

    public function invoiceBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invoice_by_user_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    /** Fatura e pagamento: 'paid' (pagamento recebido), 'invoiced' (fatura emitida, por pagar), 'pending' (sem fatura nem pagamento). */
    public function paymentStatus(): ?string
    {
        if (! in_array($this->quote_status, self::QUOTE_WORK_STATUSES, true)) {
            return null;
        }

        return $this->paid_at !== null ? 'paid' : ($this->invoice_path !== null ? 'invoiced' : 'pending');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('id');
    }

    /** Histórico das mudanças de tipo (mais recente primeiro). */
    public function typeChanges(): HasMany
    {
        return $this->hasMany(SupportTicketTypeChange::class)->orderByDesc('id');
    }

    public function tasks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupportTicketTask::class)->orderBy('position')->orderBy('id');
    }
}
