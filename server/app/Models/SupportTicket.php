<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * DMS — Ticket de suporte (scoped por company).
 */
class SupportTicket extends Model implements AuditableContract
{
    use Auditable;

    // 'site_change' é o tipo PAGO (dispara a camada de orçamento). Os restantes
    // são grátis/suporte normal.
    public const TYPES = ['idea', 'improvement', 'bug', 'suggestion', 'site_change'];
    public const STATUSES = ['open', 'in_review', 'resolved', 'closed'];

    // Fluxo de orçamento — só para tickets 'site_change' (null nos grátis).
    public const QUOTE_STATUSES = ['awaiting_quote', 'quoted', 'approved', 'paid', 'completed', 'rejected'];

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
    ];

    protected $casts = [
        'resolved_at'     => 'datetime',
        'estimated_hours' => 'decimal:2',
        'quoted_amount'   => 'decimal:2',
    ];

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

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('id');
    }
}
