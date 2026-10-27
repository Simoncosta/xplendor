<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * E-mail 1 · Uma caixa de correio ligada à XPLENDOR: a de uma pessoa (user_id) ou, no E-mail 6, a
 * da empresa (sem dono). A palavra-passe fica cifrada e nunca sai pela API (toApiArray só diz se
 * existe).
 */
class Mailbox extends Model
{
    public const TYPES = ['gmail', 'cpanel', 'manual'];

    public const STATUS_PENDING = 'por_testar';
    public const STATUS_ACTIVE = 'ativa';
    public const STATUS_ERROR = 'erro';

    protected $fillable = [
        'company_id', 'user_id', 'account_type', 'email', 'smtp_host', 'smtp_port', 'smtp_encryption',
        'imap_host', 'imap_port', 'imap_encryption', 'username', 'password', 'sent_folder', 'save_to_sent',
        'status', 'last_error', 'last_tested_at', 'last_uid', 'uidvalidity', 'unread_count', 'unread_checked_at',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'encrypted',
        'smtp_port' => 'integer',
        'imap_port' => 'integer',
        'save_to_sent' => 'boolean',
        'last_tested_at' => 'datetime',
        'last_uid' => 'integer',
        'uidvalidity' => 'integer',
        'unread_count' => 'integer',
        'unread_checked_at' => 'datetime',
    ];

    /** A caixa pessoal desta pessoa nesta empresa. */
    public function scopePersonal(Builder $query, int $companyId, int $userId): Builder
    {
        return $query->where('company_id', $companyId)->where('user_id', $userId);
    }

    /** O que a API mostra: sem a palavra-passe, só se existe. */
    public function toApiArray(): array
    {
        return [
            'account_type' => $this->account_type,
            'email' => $this->email,
            'smtp_host' => $this->smtp_host,
            'smtp_port' => $this->smtp_port,
            'smtp_encryption' => $this->smtp_encryption,
            'imap_host' => $this->imap_host,
            'imap_port' => $this->imap_port,
            'imap_encryption' => $this->imap_encryption,
            'username' => $this->username,
            'has_password' => (string) $this->getRawOriginal('password') !== '',
            'save_to_sent' => $this->save_to_sent,
            'status' => $this->status,
            'last_error' => $this->last_error,
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
        ];
    }
}
