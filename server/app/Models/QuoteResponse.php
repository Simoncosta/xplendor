<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resposta do cliente a uma versão, pelo link público: aceitação (com nome, email,
 * confirmação das condições e as linhas aceites; uma única por versão), recusa (motivo
 * opcional) ou pedido de alterações (mensagem para a equipa).
 */
class QuoteResponse extends Model
{
    public const ACCEPTED = 'accepted';
    public const REFUSED = 'refused';
    public const CHANGES_REQUESTED = 'changes_requested';

    public const UPDATED_AT = null;

    protected $fillable = [
        'quote_id', 'quote_version_id', 'accepted_version_id', 'type', 'name', 'email', 'terms_accepted', 'message',
        'accepted_line_keys', 'selection', 'after_changes_request', 'device',
    ];

    protected $casts = [
        'terms_accepted' => 'boolean',
        'after_changes_request' => 'boolean',
        'accepted_line_keys' => 'array',
        'selection' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(QuoteVersion::class, 'quote_version_id');
    }
}
