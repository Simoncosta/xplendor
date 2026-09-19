<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedLegacy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * XPLENDOR — Loja de uma empresa (restauração). Tem DOIS conjuntos de
 * credenciais: PingWin (winrest_store_id) e CoverManager (cm_slug + cm_token).
 * cm_token é cifrado em repouso e NUNCA serializado ($hidden). opened_on: dias
 * anteriores à abertura não contam (portão de honestidade).
 */
class PingwinLocation extends Model
{
    protected $fillable = [
        'company_id', 'winrest_store_id', 'winrest_name', 'display_name', 'opened_on', 'is_active',
        'cm_slug', 'cm_token', 'cm_base_url',
    ];

    protected $casts = [
        'opened_on' => 'date',
        'is_active' => 'boolean',
        'cm_token'  => EncryptedLegacy::class, // cifrado em repouso
    ];

    // O token nunca sai em JSON; o UI usa cm_connected para saber se está ligado.
    protected $hidden = ['cm_token'];

    protected $appends = ['cm_connected', 'cm_has_override'];

    // A loja está "configurada" para reservas quando tem slug (o token resolve-se
    // ao nível da empresa, com override opcional por loja). cm_has_override indica
    // se a loja tem o seu próprio token.
    public function getCmConnectedAttribute(): bool
    {
        return ! empty($this->cm_slug);
    }

    public function getCmHasOverrideAttribute(): bool
    {
        return ! empty($this->cm_token);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function dailySales(): HasMany
    {
        return $this->hasMany(PingwinDailySale::class, 'location_id');
    }
}
