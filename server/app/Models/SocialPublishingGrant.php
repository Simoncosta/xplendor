<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * F2.8 · A autorização de publicação de uma empresa ("Ligar publicação"), à parte da ligação de
 * leitura (SocialConnection). Os tokens das Páginas ficam em cada conta (publish_access_token).
 */
class SocialPublishingGrant extends Model
{
    /** O que "Ligar publicação" pede, além das permissões de leitura. */
    public const SCOPES = ['instagram_content_publish', 'pages_manage_posts'];

    protected $fillable = ['company_id', 'meta_user_id', 'access_token', 'token_expires_at', 'granted_scopes', 'connected_by_user_id', 'connected_at'];

    protected $hidden = ['access_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'granted_scopes' => 'array',
        'connected_at' => 'datetime',
    ];
}
