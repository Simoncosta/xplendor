<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** XPLENDOR — O histórico das regras de classificação: quem, quando, antes e depois. */
class FinanceRuleEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'rule_id', 'action', 'before', 'after', 'user_id', 'reason', 'created_at'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
