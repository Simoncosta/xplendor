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

    /** Canais suportados. "site" = artigo do blog (ligado por blog_id). */
    public const CHANNELS = ['instagram', 'facebook', 'site'];

    /** Formato único do canal "site". */
    public const SITE_FORMAT = 'Artigo';

    /**
     * FORMATO da publicação, no vocabulário do publicador (F2), por rede. O campo
     * "format" abaixo passou a ser o TIPO DE CONTEÚDO (os 18 valores mantêm-se).
     */
    public const MEDIA_FORMATS = [
        'instagram' => ['ig_feed_image', 'ig_carousel', 'ig_reel', 'ig_story'],
        'facebook'  => ['fb_post', 'fb_photos', 'fb_video', 'fb_reel', 'fb_story'],
    ];

    public const MEDIA_FORMAT_LABELS = [
        'ig_feed_image' => 'Imagem (feed)',
        'ig_carousel'   => 'Carrossel',
        'ig_reel'       => 'Reel (vídeo)',
        'ig_story'      => 'Story',
        'fb_post'       => 'Publicação (texto ou ligação)',
        'fb_photos'     => 'Fotografias',
        'fb_video'      => 'Vídeo',
        'fb_reel'       => 'Reel',
        'fb_story'      => 'Story',
    ];

    /** Tipos de conteúdo Insta/FB (lista fixa por agora; antes chamados "formatos"). */
    public const FORMATS = [
        'Carrossel', 'Imagem única', 'Reels', 'Stories', 'Vídeo', 'Live',
        'Infográfico', 'Citação', 'Checklist', 'Tutorial', 'Antes e depois',
        'Bastidores', 'Enquete/Interativo', 'Depoimento', 'Dica de expert',
        'Notícia', 'Institucional', 'Sazonal',
    ];

    protected $fillable = [
        'company_id', 'publish_date', 'title', 'format', 'media_format', 'status',
        'channel', 'keyword', 'anchor_id', 'own_anchor_id', 'blog_id',
    ];

    protected $casts = [
        'publish_date'  => 'date',
        'anchor_id'     => 'integer',
        'own_anchor_id' => 'integer',
        'blog_id'       => 'integer',
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

    /** Artigo do blog ligado (canal "site") — opcional; o estado mostrado vem do artigo. */
    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    /** Âncora própria ligada (editorial_own_anchors) — opcional. */
    /** Criativo aceite (Sugerir criativo). */
    public function creative(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EditorialPostCreative::class, 'editorial_post_id');
    }

    public function ownAnchor(): BelongsTo
    {
        return $this->belongsTo(EditorialOwnAnchor::class, 'own_anchor_id');
    }
}
