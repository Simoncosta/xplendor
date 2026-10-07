<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Relação de gestão: "a agência X gere a empresa Y". Só o estado ACTIVE dá acesso; os
 * outros estados ficam como histórico. active_key = managed_company_id enquanto ativa
 * (UNIQUE: uma só agência ativa por empresa).
 */
class CompanyManagement extends Model
{
    protected $table = 'company_managements';

    public const ORIGIN_PLATFORM = 'platform';
    public const ORIGIN_CREATED_BY_AGENCY = 'created_by_agency';
    public const ORIGIN_REQUEST_ACCEPTED = 'request_accepted';

    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const DECLINED = 'declined';
    public const WITHDRAWN = 'withdrawn';
    public const ENDED = 'ended';
    public const EXPIRED = 'expired';
    public const STATUSES = [self::PENDING, self::ACTIVE, self::DECLINED, self::WITHDRAWN, self::ENDED, self::EXPIRED];

    public const SCOPE_ALL = 'all';
    public const SCOPE_ASSIGNED = 'assigned';

    public const SIDE_COMPANY = 'company';
    public const SIDE_AGENCY = 'agency';
    public const SIDE_PLATFORM = 'platform';

    protected $fillable = [
        'agency_company_id', 'managed_company_id', 'origin', 'status', 'active_key', 'team_scope',
        'requested_by_user_id', 'requested_at', 'request_message', 'agency_authorization_declared_at',
        'responded_by_user_id', 'responded_at', 'decline_reason', 'request_expires_at',
        'ended_by_user_id', 'ended_by_side', 'ended_at', 'end_reason', 'data_outcome', 'notes',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'agency_authorization_declared_at' => 'datetime',
        'responded_at' => 'datetime',
        'request_expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'agency_company_id');
    }

    public function managed(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'managed_company_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CompanyManagementMember::class, 'management_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
