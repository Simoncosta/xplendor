<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Envio seguro de documentos para financiamento: o link privado que o vendedor gera para um
 * cliente. O código (64 caracteres aleatórios) guarda-se em hash e numa cópia cifrada; o link é
 * /enviar-documentos#<código> (o fragmento não chega ao servidor, aos registos nem ao Referer).
 * Estados: ativo, expirado, revogado e apagado (os ficheiros já foram apagados pela retenção).
 */
class FinancingLink extends Model
{
    public const TOKEN_LENGTH = 64;

    public const ACTIVE = 'ativo';
    public const EXPIRED = 'expirado';
    public const REVOKED = 'revogado';
    public const PURGED = 'apagado';

    protected $fillable = [
        'company_id', 'car_id', 'car_lead_id', 'created_by_user_id', 'customer_name', 'requested', 'token_hash', 'token_encrypted',
        'expires_at', 'revoked_at', 'revoked_by_user_id', 'max_files', 'max_bytes', 'folder_id', 'consented_at', 'last_upload_at',
        'last_notified_at', 'purge_warned_at', 'purged_at',
        // Titulares (financiamento): o pedido, o número do titular, a relação do segundo e os dados preenchidos.
        'financing_request_id', 'holder', 'relationship', 'relationship_other', 'phone', 'email', 'housing', 'housing_other', 'housing_monthly_eur', 'details_at',
    ];

    public const RELATIONSHIPS = [
        'conjuge' => 'Cônjuge', 'unido_facto' => 'Unido de facto', 'pai_mae' => 'Pai ou mãe', 'filho_filha' => 'Filho ou filha',
        'irmao_irma' => 'Irmão ou irmã', 'outro' => 'Outro',
    ];

    public const HOUSING = [
        'arrendada' => 'Arrendada', 'propria_credito' => 'Própria, com crédito à habitação', 'propria_sem_credito' => 'Própria, sem crédito',
        'outra' => 'Outra',
    ];

    /** Habitações em que se pede o valor mensal (a renda ou a prestação). */
    public const HOUSING_WITH_AMOUNT = ['arrendada', 'propria_credito'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected $casts = [
        'requested' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'consented_at' => 'datetime', 'last_upload_at' => 'datetime',
        'last_notified_at' => 'datetime', 'purge_warned_at' => 'datetime', 'purged_at' => 'datetime', 'max_files' => 'integer', 'max_bytes' => 'integer',
        'holder' => 'integer', 'housing_monthly_eur' => 'decimal:2', 'details_at' => 'datetime',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Novo código: devolve [o código, os campos a gravar]. @return array{0: string, 1: array{token_hash: string, token_encrypted: string}} */
    public static function newToken(): array
    {
        $token = Str::random(self::TOKEN_LENGTH);

        return [$token, ['token_hash' => self::hashToken($token), 'token_encrypted' => Crypt::encryptString($token)]];
    }

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
        return rtrim((string) config('app.frontend_url'), '/') . '/enviar-documentos#' . $this->token();
    }

    public function state(): string
    {
        return match (true) {
            $this->purged_at !== null => self::PURGED,
            $this->revoked_at !== null => self::REVOKED,
            $this->expires_at->isPast() => self::EXPIRED,
            default => self::ACTIVE,
        };
    }

    public function isOpen(): bool
    {
        return $this->state() === self::ACTIVE;
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FinancingRequest::class, 'financing_request_id');
    }

    public function relationshipLabel(): ?string
    {
        if (! $this->relationship) {
            return null;
        }

        return $this->relationship === 'outro' && $this->relationship_other ? $this->relationship_other : (self::RELATIONSHIPS[$this->relationship] ?? $this->relationship);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CarLead::class, 'car_lead_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FinancingDocument::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(FinancingConsent::class);
    }
}
