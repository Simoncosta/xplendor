<?php

namespace App\Models;

use App\Casts\EncryptedLegacy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyIntegration extends Model
{
    protected $fillable = [
        'company_id',
        'platform',
        'connected_by_user_id',
        'setup_link_id',
        'access_token',
        'account_id',
        'page_id',
        'property_id',
        'config',
        'token_expires_at',
        'status',
        'error_message',
        'last_synced_at',
        // Ingestão Meta ao nível da conta — estado real do sync (ver migração 2026_10_03_100100).
        'insights_sync_status',
        'insights_backfilled_at',
        'insights_backfill_months',
        'insights_last_run_at',
        'insights_error',
        'insights_synced_at',
        'insights_synced_until',
        // Leitura dos públicos personalizados (motor de recomendações).
        'audiences_sync_status',
        'audiences_synced_at',
        'audiences_error',
        // Ingestão Meta por anúncio (ver migração 2026_11_05_100300).
        'ad_insights_sync_status',
        'ad_insights_account_id',
        'ad_insights_backfill_cursor',
        'ad_insights_backfilled_at',
        'ad_insights_synced_until',
        'ad_insights_synced_at',
        'ad_insights_last_run_at',
        'ad_insights_error',
    ];

    protected $hidden = ['access_token'];

    protected $casts = [
        // Token cifrado em repouso. Cast tolerante: decifra, e devolve cru se
        // ainda estiver em texto simples (transição sem partir o pipeline).
        'access_token'     => EncryptedLegacy::class,
        'config'           => 'array',
        'token_expires_at' => 'datetime',
        'last_synced_at'   => 'datetime',
        'insights_backfilled_at' => 'datetime',
        'insights_backfill_months' => 'integer',
        'insights_last_run_at'   => 'datetime',
        'insights_synced_at'     => 'datetime',
        'insights_synced_until'  => 'date',
        'audiences_synced_at'    => 'datetime',
        'ad_insights_backfill_cursor' => 'date',
        'ad_insights_backfilled_at'   => 'datetime',
        'ad_insights_synced_until'    => 'date',
        'ad_insights_synced_at'       => 'datetime',
        'ad_insights_last_run_at'     => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    // Scopess
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }
}
