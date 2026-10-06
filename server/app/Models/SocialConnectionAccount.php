<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Página de Facebook ou conta de Instagram profissional escolhida numa ligação das
 * redes sociais. A conta principal de cada rede alimenta o histórico de seguidores.
 */
class SocialConnectionAccount extends Model
{
    protected $fillable = [
        'company_id', 'social_connection_id', 'platform', 'external_id', 'page_id', 'name', 'username', 'profile_picture_path', 'profile_picture_updated_at',
        'page_access_token', 'is_primary', 'last_followers_count', 'last_read_at', 'last_error_at', 'last_error_kind',
    ];

    protected $hidden = ['page_access_token'];

    protected $casts = [
        'page_access_token' => 'encrypted',
        'is_primary' => 'boolean',
        'last_followers_count' => 'integer',
        'last_read_at' => 'datetime',
        'last_error_at' => 'datetime',
        'profile_picture_updated_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'social_connection_id');
    }
}
