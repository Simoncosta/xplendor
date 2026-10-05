<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versão congelada de um orçamento, criada ao enviar: tudo o que o cliente recebeu
 * (snapshot) e o PDF desse momento. Nunca se altera.
 */
class QuoteVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'quote_id', 'version', 'number', 'snapshot', 'pdf_path', 'pdf_sha256', 'sent_at', 'valid_until', 'sent_by_user_id',
    ];

    protected $casts = [
        'snapshot'    => 'array',
        'sent_at'     => 'datetime',
        'valid_until' => 'date',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
