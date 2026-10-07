<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Link de configuração do cliente: abre-se sem conta (token no fragmento do endereço,
 * enviado no cabeçalho X-Setup-Token) e serve para o cliente autorizar ele próprio as
 * ligações de marketing da empresa. Um link ativo por empresa; 14 dias, renovável (o mesmo
 * token) e revogável. Os passos escolhidos ao gerar o link guardam o estado de cada um.
 */
class CompanySetupLink extends Model
{
    public const TOKEN_LENGTH = 64;

    public const VALIDITY_DAYS = 14;

    public const STEP_SOCIAL = 'social';
    public const STEP_META_ADS = 'meta_ads';
    public const STEP_GA4 = 'ga4';
    /** Ordem dos passos na página. */
    public const STEPS = [
        self::STEP_SOCIAL => 'Facebook e Instagram',
        self::STEP_META_ADS => 'Anúncios da Meta',
        self::STEP_GA4 => 'Google Analytics 4',
    ];
    /** Chave da tarefa do ticket de arranque que cada passo marca. */
    public const STEP_TASK_KEYS = [
        self::STEP_SOCIAL => SupportTicketTask::KEY_SOCIAL_ACCESS,
        self::STEP_META_ADS => SupportTicketTask::KEY_META_ADS_ACCESS,
        self::STEP_GA4 => SupportTicketTask::KEY_GA4_ACCESS,
    ];

    // Estado de cada passo.
    public const PENDING = 'pending';
    public const DONE = 'done';
    public const ERROR = 'error';
    public const NOT_APPROVED = 'not_approved';

    // Estado do link.
    public const STATE_OPEN = 'open';
    public const STATE_EXPIRED = 'expired';
    public const STATE_REVOKED = 'revoked';

    public const REVOKED_MANUAL = 'manual';
    public const REVOKED_REPLACED = 'replaced';

    protected $fillable = [
        'company_id', 'created_by_user_id', 'impersonator_user_id', 'company_management_id', 'support_ticket_id', 'token_hash', 'token_encrypted',
        'steps', 'expires_at', 'revoked_at', 'revoked_reason', 'revoked_by_user_id', 'completed_at',
    ];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected $casts = [
        'steps' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'completed_at' => 'datetime',
        'first_opened_at' => 'datetime',
        'last_opened_at' => 'datetime',
        'last_open_alert_at' => 'datetime',
        'open_count' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function management(): BelongsTo
    {
        return $this->belongsTo(CompanyManagement::class, 'company_management_id');
    }

    // ── Token ────────────────────────────────────────────────────────────────

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{token: string, token_hash: string, token_encrypted: string} */
    public static function newToken(): array
    {
        $token = Str::random(self::TOKEN_LENGTH);

        return ['token' => $token, 'token_hash' => self::hashToken($token), 'token_encrypted' => Crypt::encryptString($token)];
    }

    /** Também devolve links revogados ou expirados (a página mostra o motivo). */
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

    /** Endereço público: o token só no fragmento (nunca chega aos registos do servidor). */
    public function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/configurar#' . $this->token();
    }

    // ── Estado ───────────────────────────────────────────────────────────────

    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => self::STATE_REVOKED,
            $this->expires_at === null || $this->expires_at->isPast() => self::STATE_EXPIRED,
            default => self::STATE_OPEN,
        };
    }

    public function isOpen(): bool
    {
        return $this->state() === self::STATE_OPEN;
    }

    /** @return string[] os passos escolhidos, pela ordem da página */
    public function stepKeys(): array
    {
        return array_values(array_filter(array_keys(self::STEPS), fn ($k) => array_key_exists($k, (array) $this->steps)));
    }

    public function hasStep(string $step): bool
    {
        return array_key_exists($step, (array) $this->steps);
    }

    public function step(string $step): array
    {
        return (array) (((array) $this->steps)[$step] ?? []);
    }

    /** Atualiza um passo (junta os campos dados ao estado guardado). */
    public function patchStep(string $step, array $fields): void
    {
        $steps = (array) $this->steps;
        $steps[$step] = array_merge(self::blankStep(), (array) ($steps[$step] ?? []), $fields);
        $this->steps = $steps;
    }

    public function allStepsDone(): bool
    {
        foreach ($this->stepKeys() as $k) {
            if (($this->step($k)['status'] ?? null) !== self::DONE) {
                return false;
            }
        }

        return $this->stepKeys() !== [];
    }

    public static function blankStep(): array
    {
        return [
            'status' => self::PENDING, 'started_at' => null, 'done_at' => null, 'error' => null, 'detail' => null,
            'stalled_notified_at' => null, 'not_approved_notified_at' => null,
        ];
    }
}
