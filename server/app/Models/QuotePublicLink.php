<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Link público de UMA versão enviada de um orçamento. O token tem 64 caracteres
 * aleatórios; na base de dados só fica o hash (pesquisa) e uma cópia cifrada (para a
 * equipa o copiar de novo). Quem tem o link só vê aquela versão.
 */
class QuotePublicLink extends Model
{
    public const TOKEN_LENGTH = 64;

    protected $fillable = ['quote_id', 'quote_version_id', 'token_hash', 'token_encrypted', 'revoked_at'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected $casts = ['revoked_at' => 'datetime'];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Cria o link de uma versão (ou devolve o que já existe). */
    public static function forVersion(QuoteVersion $version): self
    {
        $existing = self::where('quote_version_id', $version->id)->first();
        if ($existing) {
            return $existing;
        }
        $token = Str::random(self::TOKEN_LENGTH);

        return self::create([
            'quote_id' => $version->quote_id,
            'quote_version_id' => $version->id,
            'token_hash' => self::hashToken($token),
            'token_encrypted' => Crypt::encryptString($token),
        ]);
    }

    public static function findByToken(string $token): ?self
    {
        if (strlen($token) !== self::TOKEN_LENGTH || ! ctype_alnum($token)) {
            return null;
        }

        return self::where('token_hash', self::hashToken($token))->whereNull('revoked_at')->first();
    }

    public function token(): string
    {
        return Crypt::decryptString($this->token_encrypted);
    }

    public function url(): string
    {
        // Token no fragmento (#): não chega ao servidor, aos registos de acesso nem ao Referer.
        return rtrim((string) config('app.frontend_url'), '/') . '/orcamento#' . $this->token();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(QuoteVersion::class, 'quote_version_id');
    }
}
