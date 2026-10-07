<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de "Nova empresa gerida": o admin da agência pede, o root aprova (a empresa nasce
 * gerida pela agência) ou recusa com motivo. A agência vê o estado dos seus pedidos.
 */
class ManagedCompanyRequest extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const STATUSES = [self::PENDING, self::APPROVED, self::DECLINED];

    protected $fillable = [
        'agency_company_id', 'requested_by_user_id', 'name', 'content_sector_id', 'contact_name', 'contact_email', 'contact_phone',
        'note', 'authorization_declared_at', 'status', 'decided_by_user_id', 'decided_at', 'decline_reason', 'company_id', 'management_id',
    ];

    protected $casts = ['authorization_declared_at' => 'datetime', 'decided_at' => 'datetime'];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'agency_company_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(ContentSector::class, 'content_sector_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
