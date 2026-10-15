<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** XPLENDOR — Auditoria: uma mudança de estado de um achado (por uma pessoa ou por uma corrida). */
class AuditFindingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['finding_id', 'company_id', 'from_state', 'to_state', 'user_id', 'run_id', 'reason', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
