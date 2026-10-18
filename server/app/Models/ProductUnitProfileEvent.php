<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** XPLENDOR — Auditoria v2: uma decisão sobre o perfil de unidades (quem, quando, antes, depois, porquê). */
class ProductUnitProfileEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'profile_id', 'user_id', 'action', 'before', 'after', 'reason', 'created_at'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
