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
        'access_token',
        'account_id',
        'page_id',
        'property_id',
        'config',
        'token_expires_at',
        'status',
        'error_message',
        'last_synced_at',
    ];

    protected $hidden = ['access_token'];

    protected $casts = [
        // Token cifrado em repouso. Cast tolerante: decifra, e devolve cru se
        // ainda estiver em texto simples (transição sem partir o pipeline).
        'access_token'     => EncryptedLegacy::class,
        'config'           => 'array',
        'token_expires_at' => 'datetime',
        'last_synced_at'   => 'datetime',
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
