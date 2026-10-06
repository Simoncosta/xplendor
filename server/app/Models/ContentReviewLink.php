<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Link de aprovação de um lote de publicações (F3c), com o padrão do link do orçamento:
 * token de 64 caracteres aleatórios; na base de dados só fica o hash (pesquisa) e uma
 * cópia cifrada (para a equipa o copiar de novo). Quem tem o link só vê as publicações do
 * lote. Expirado ou revogado: só consulta, sem ficheiros.
 */
class ContentReviewLink extends Model
{
    public const TOKEN_LENGTH = 64;

    public const STATE_OPEN = 'open';
    public const STATE_EXPIRED = 'expired';
    public const STATE_REVOKED = 'revoked';

    protected $fillable = [
        'company_id', 'title', 'token_hash', 'token_encrypted', 'recipient_name', 'recipient_email',
        'sent_by_user_id', 'impersonator_user_id', 'last_sent_at', 'expires_at', 'revoked_at',
    ];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected $casts = [
        'last_sent_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime',
        'first_opened_at' => 'datetime', 'last_opened_at' => 'datetime', 'last_open_alert_at' => 'datetime',
        'client_reminder_sent_at' => 'datetime', 'team_reminder_sent_at' => 'datetime',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Novo token: devolve [hash, cifrado] para gravar. */
    public static function newToken(): array
    {
        $token = Str::random(self::TOKEN_LENGTH);

        return ['token_hash' => self::hashToken($token), 'token_encrypted' => Crypt::encryptString($token)];
    }

    /** O link do token (também revogado ou expirado: a página mostra-o só para consulta). */
    public static function findByToken(string $token): ?self
    {
        if (strlen($token) !== self::TOKEN_LENGTH || ! ctype_alnum($token)) {
            return null;
        }

        return self::where('token_hash', self::hashToken($token))->first();
    }

    public function token(): string
    {
        return Crypt::decryptString($this->token_encrypted);
    }

    public function url(): string
    {
        // Token no fragmento (#): não chega ao servidor, aos registos de acesso nem ao Referer.
        return rtrim((string) config('app.frontend_url'), '/') . '/aprovar#' . $this->token();
    }

    public function state(): string
    {
        if ($this->revoked_at) {
            return self::STATE_REVOKED;
        }

        return $this->expires_at->isPast() ? self::STATE_EXPIRED : self::STATE_OPEN;
    }

    public function isOpen(): bool
    {
        return $this->state() === self::STATE_OPEN;
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContentReviewLinkItem::class)->orderBy('position')->orderBy('id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
