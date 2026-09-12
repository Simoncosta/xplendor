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

    public const TYPES = ['idea', 'improvement', 'bug', 'suggestion'];
    public const STATUSES = ['open', 'in_review', 'resolved', 'closed'];

    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'title',
        'description',
        'status',
        'screenshot_path',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

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
