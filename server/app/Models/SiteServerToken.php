<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Site do consultor: o token do contentor do site (servidor a servidor). Um por instalação,
 * ligado aos sites que pode servir; o consultor é reconhecido pelo host. Só o hash fica na base.
 */
class SiteServerToken extends Model
{
    protected $fillable = ['name', 'token_hash', 'last_used_at', 'revoked_at'];

    protected $casts = ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected $hidden = ['token_hash'];

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(ConsultantSite::class, 'site_server_token_sites');
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Cria um token e devolve-o em claro (a única vez que existe em claro). @return array{0: self, 1: string} */
    public static function issue(string $name): array
    {
        $plain = 'xst_' . Str::random(48);

        return [self::create(['name' => $name, 'token_hash' => self::hash($plain)]), $plain];
    }
}
