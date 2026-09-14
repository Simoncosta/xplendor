<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * XPLENDOR — Orçamento avulso (gestão comercial, super-admin). Transversal,
 * não scoped por company. Base do mini-CRM comercial.
 */
class Quote extends Model implements AuditableContract
{
    use Auditable;

    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'company_id',
        'client_name',
        'client_contact',
        'description',
        'amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // Garante 'pending' na instância criada (a BD também tem o default como backstop).
    protected $attributes = [
        'status' => 'pending',
    ];

    /** Ligação opcional a um stand da plataforma (futuro; null hoje). */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
