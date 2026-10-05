<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ligação das redes sociais (Instagram e Facebook) de uma empresa. Separada da
 * ligação dos anúncios: outro token, outras permissões, desliga-se sozinha.
 */
class SocialConnection extends Model
{
    /** As únicas permissões pedidas (e as únicas retiradas ao desligar). */
    public const SCOPES = ['pages_show_list', 'pages_read_engagement', 'instagram_basic'];

    public const STATUS_PENDING_SELECTION = 'pending_selection';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_PERMISSION_REMOVED = 'permission_removed';
    public const STATUS_NOT_APPROVED = 'not_approved';
    public const STATUS_REVOKED = 'revoked';

    /** Estados em que o job diário tenta ler (a aprovação da Meta pode chegar entretanto). */
    public const READABLE = [self::STATUS_ACTIVE, self::STATUS_NOT_APPROVED];

    public const ERROR_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'meta_user_id', 'access_token', 'token_expires_at', 'granted_scopes', 'status',
        'connected_by_user_id', 'connected_at', 'last_read_at', 'last_error_at', 'last_error_kind', 'last_error_message',
    ];

    protected $hidden = ['access_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'granted_scopes' => 'array',
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'last_read_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(SocialConnectionAccount::class);
    }
}
