<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de gestão de uma empresa EXISTENTE: a agência indica o NIPC ou o email de um admin.
 * Fica registado mesmo sem empresa correspondente, para a agência nunca saber se a empresa
 * existe; managed_company_id só é visto pela plataforma e pela empresa. Os admins da empresa
 * aceitam ou recusam na app; expira aos 14 dias; a agência pode retirá-lo.
 */
class ManagementRequest extends Model
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const WITHDRAWN = 'withdrawn';
    public const EXPIRED = 'expired';

    public const BY_NIPC = 'nipc';
    public const BY_EMAIL = 'email';

    public const EXPIRES_IN_DAYS = 14;

    protected $fillable = [
        'agency_company_id', 'requested_by_user_id', 'identifier_type', 'identifier', 'message', 'authorization_declared_at',
        'status', 'managed_company_id', 'management_id', 'expires_at', 'responded_by_user_id', 'responded_at', 'decline_reason', 'withdrawn_at',
        'identifier_scrubbed_at',
    ];

    protected $casts = [
        'authorization_declared_at' => 'datetime', 'expires_at' => 'datetime', 'responded_at' => 'datetime', 'withdrawn_at' => 'datetime',
        'identifier_scrubbed_at' => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'agency_company_id');
    }

    public function managed(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'managed_company_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** Pendente e dentro do prazo (o job marca as expiradas; isto protege entre execuções). */
    public function isOpen(): bool
    {
        return $this->status === self::PENDING && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
