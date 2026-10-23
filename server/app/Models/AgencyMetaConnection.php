<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parceiro 2: a ligação da Meta da agência. Uma por agência, feita por um administrador da agência.
 * O token (de utilizador, longa duração) fica cifrado e nunca sai na resposta. Desligar esta ligação
 * é a única ação que revoga na Meta (SharedMetaUser decide o que pode revogar sem cortar outras).
 */
class AgencyMetaConnection extends Model
{
    /** As permissões pedidas: as três das redes, as dos portfólios e a leitura dos anúncios. */
    public const SCOPES = ['business_management', 'pages_show_list', 'pages_read_engagement', 'instagram_basic', 'ads_read'];

    /** As que se juntam às das redes no pedido de autorização (as das redes vão sempre). */
    public const EXTRA_SCOPES = ['business_management', 'ads_read'];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_PERMISSION = 'permission';

    /** Dias antes do fim do token em que a agência é avisada para renovar. */
    public const RENEW_WARNING_DAYS = 10;

    protected $fillable = ['agency_company_id', 'meta_user_id', 'access_token', 'token_expires_at', 'granted_scopes', 'status',
        'connected_by_user_id', 'connected_at', 'renewal_warned_at'];

    protected $hidden = ['access_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'granted_scopes' => 'array',
        'connected_at' => 'datetime',
        'renewal_warned_at' => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'agency_company_id');
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }
}
