<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versão do conteúdo de uma publicação (legenda, hashtags, chamada à ação, primeiro
 * comentário, formato). Editável só em rascunho; congelada quando é enviada ao cliente.
 * Editar uma versão congelada cria a versão seguinte.
 */
class EditorialPostVersion extends Model
{
    public const DRAFT = 'draft';
    public const SENT = 'sent';
    public const APPROVED = 'approved';
    public const CHANGES_REQUESTED = 'changes_requested';
    public const SUPERSEDED = 'superseded';

    public const CONTENT_FIELDS = ['caption', 'network_captions', 'hashtags', 'cta', 'first_comment', 'media_formats'];

    protected $fillable = [
        'company_id', 'editorial_post_id', 'number', 'caption', 'network_captions', 'hashtags', 'cta', 'first_comment', 'media_formats',
        'status', 'created_by_user_id', 'impersonator_user_id', 'updated_by_user_id', 'updated_by_impersonator_id',
        'sent_at', 'frozen_at',
    ];

    protected $casts = [
        'hashtags' => 'array',
        'media_formats' => 'array',
        'network_captions' => 'array',
        'number' => 'integer',
        'sent_at' => 'datetime',
        'frozen_at' => 'datetime',
    ];

    /** A legenda numa rede: a própria, se houver; senão a mesma para todas. */
    public function captionFor(string $network): ?string
    {
        $own = $this->network_captions[$network] ?? null;

        return is_string($own) && trim($own) !== '' ? $own : $this->caption;
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(EditorialPost::class, 'editorial_post_id');
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }

    /** Pessoa real que criou a versão (a equipa em sessão como cliente conta como ela própria). */
    public function authorPersonId(): ?int
    {
        return $this->impersonator_user_id ?? $this->created_by_user_id;
    }
}
