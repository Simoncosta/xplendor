<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Cobrança da XPLENDOR: os dados próprios de uma despesa com source 'xplendor' (1 para 1).
 * A XPLENDOR não emite faturas: guarda a fatura em PDF (disco privado), gere o estado e
 * os lembretes. O link seguro segue o padrão dos orçamentos: token de 64 caracteres,
 * na base só o hash (pesquisa) e uma cópia cifrada (para mostrar ao root e reenviar).
 */
class ExpenseCharge extends Model
{
    public const OPEN = 'open';
    public const PAYMENT_INDICATED = 'payment_indicated';
    public const PAID = 'paid';
    public const CANCELLED = 'cancelled';
    public const STATUSES = [self::OPEN, self::PAYMENT_INDICATED, self::PAID, self::CANCELLED];

    public const TOKEN_LENGTH = 64;
    /** Depois de paga, o link fica válido como recibo durante este tempo. */
    public const LINK_DAYS_AFTER_PAID = 90;

    protected $fillable = [
        'expense_id', 'company_id', 'status', 'due_date', 'invoice_path', 'invoice_name', 'invoice_size_bytes', 'created_by_user_id',
        'cancel_reason', 'cancelled_at', 'cancelled_by_user_id',
        'payment_indicated_at', 'payment_indicated_via', 'payment_indicated_by_user_id', 'payment_note', 'proof_path', 'proof_name', 'proof_mime', 'proof_size_bytes',
        'refused_at', 'refuse_note', 'paid_at', 'paid_by_user_id',
        'last_reminder_on', 'reminders_sent', 'no_recipient_alerted_at',
        'token_hash', 'token_encrypted', 'link_revoked_at',
        'open_count', 'first_opened_at', 'last_opened_at', 'last_open_alert_at',
    ];

    protected $hidden = ['token_hash', 'token_encrypted', 'invoice_path', 'proof_path'];

    protected $casts = [
        'due_date' => 'date',
        'cancelled_at' => 'datetime',
        'payment_indicated_at' => 'datetime',
        'refused_at' => 'datetime',
        'paid_at' => 'datetime',
        'last_reminder_on' => 'date',
        'no_recipient_alerted_at' => 'datetime',
        'link_revoked_at' => 'datetime',
        'first_opened_at' => 'datetime',
        'last_opened_at' => 'datetime',
        'last_open_alert_at' => 'datetime',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Um token novo (devolve o token em claro; guarda o hash e a cópia cifrada). */
    public function issueToken(): string
    {
        $token = Str::random(self::TOKEN_LENGTH);
        $this->forceFill(['token_hash' => self::hashToken($token), 'token_encrypted' => Crypt::encryptString($token), 'link_revoked_at' => null]);

        return $token;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** A cobrança do token, se o link ainda for válido. */
    public static function findByToken(string $token): ?self
    {
        if (strlen($token) !== self::TOKEN_LENGTH || ! ctype_alnum($token)) {
            return null;
        }
        $charge = self::where('token_hash', self::hashToken($token))->first();

        return $charge && $charge->linkIsValid() ? $charge : null;
    }

    public function linkIsValid(): bool
    {
        if ($this->link_revoked_at || $this->status === self::CANCELLED) {
            return false;
        }

        return $this->status !== self::PAID || ! $this->paid_at || $this->paid_at->copy()->addDays(self::LINK_DAYS_AFTER_PAID)->isFuture();
    }

    public function token(): string
    {
        return Crypt::decryptString($this->token_encrypted);
    }

    public function publicUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/cobranca#' . $this->token();
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [self::OPEN, self::PAYMENT_INDICATED], true)
            && $this->due_date->toDateString() < \App\Services\Billing\ChargeService::today()->toDateString();
    }
}
