<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Fatia 4: uma gravação de uma ficha técnica na PingWin (e o histórico de alterações).
 * Estados: queued → running → ok | mismatch (a releitura não bate) | conflict (a ficha mudou na
 * PingWin desde a leitura) | failed. Nunca se repete sozinha.
 */
class RecipeWrite extends Model
{
    public const ACTIVE = ['queued', 'running'];

    protected $fillable = [
        'company_id', 'product_pingwin_id', 'user_id', 'operation', 'bom_pingwin_id', 'valid_from',
        'before', 'after', 'plan', 'status', 'progress', 'result', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'before' => 'array',
        'after' => 'array',
        'plan' => 'array',
        'progress' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
