<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Configurações: uma alteração de uma definição (quem, quando, antes e depois). */
class SettingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['scope', 'company_id', 'key', 'before', 'after', 'user_id', 'reason'];

    protected $casts = ['before' => 'json', 'after' => 'json'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
