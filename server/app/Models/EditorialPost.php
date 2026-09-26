<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Linha Editorial (Publicações P1). Enums FIXOS em código (formato/status/canal).
 * Ligação opcional a UMA âncora: herdada (anchor_id) OU própria (own_anchor_id). Ver migration.
 */
class EditorialPost extends Model
{
    /** Estados (definem o board futuro). */
    public const STATUSES = ['rascunho', 'revisao', 'publicada', 'otimizada'];

    /** Canais suportados. */
    public const CHANNELS = ['instagram', 'facebook'];

    /** Formatos Insta/FB (lista fixa por agora). */
    public const FORMATS = [
        'Carrossel', 'Imagem única', 'Reels', 'Stories', 'Vídeo', 'Live',
        'Infográfico', 'Citação', 'Checklist', 'Tutorial', 'Antes e depois',
        'Bastidores', 'Enquete/Interativo', 'Depoimento', 'Dica de expert',
        'Notícia', 'Institucional', 'Sazonal',
    ];

    protected $fillable = [
        'company_id', 'publish_date', 'title', 'format', 'status',
        'channel', 'keyword', 'anchor_id', 'own_anchor_id',
    ];

    protected $casts = [
        'publish_date'  => 'date',
        'anchor_id'     => 'integer',
        'own_anchor_id' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Âncora herdada ligada (content_anchors) — opcional. */
    public function anchor(): BelongsTo
    {
        return $this->belongsTo(ContentAnchor::class, 'anchor_id');
    }

    /** Âncora própria ligada (editorial_own_anchors) — opcional. */
    public function ownAnchor(): BelongsTo
    {
        return $this->belongsTo(EditorialOwnAnchor::class, 'own_anchor_id');
    }
}
