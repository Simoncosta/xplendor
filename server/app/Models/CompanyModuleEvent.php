<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histórico de módulos: um módulo ligado ou desligado numa empresa (origem, nota e quem). */
class CompanyModuleEvent extends Model
{
    public const UPDATED_AT = null;

    public const ENABLED = 'enabled';
    public const DISABLED = 'disabled';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_PRESET = 'preset';
    public const SOURCE_AGENCY = 'agency';

    protected $fillable = ['company_id', 'module_key', 'action', 'source', 'note', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
