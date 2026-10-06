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
    /** Estado antigo: mantém-se sincronizado a partir da etapa (compatibilidade). */
    public const STATUSES = ['rascunho', 'revisao', 'publicada', 'otimizada'];

    /** Etapas do fluxo de produção e aprovação (F3), por ordem. */
    public const STAGE_IDEA = 'idea';
    public const STAGE_PLANNING = 'planning';
    public const STAGE_PRODUCTION = 'production';
    public const STAGE_INTERNAL_REVIEW = 'internal_review';
    public const STAGE_CLIENT_REVIEW = 'client_review';
    public const STAGE_SCHEDULED = 'scheduled';
    public const STAGE_PUBLISHED = 'published';
    public const STAGE_ANALYSIS = 'analysis';
    public const STAGES = [
        self::STAGE_IDEA, self::STAGE_PLANNING, self::STAGE_PRODUCTION, self::STAGE_INTERNAL_REVIEW,
        self::STAGE_CLIENT_REVIEW, self::STAGE_SCHEDULED, self::STAGE_PUBLISHED, self::STAGE_ANALYSIS,
    ];

    /** Etapa → estado antigo (e o inverso, para quem ainda envia o estado antigo ao criar). */
    public const STAGE_TO_STATUS = [
        self::STAGE_IDEA => 'rascunho', self::STAGE_PLANNING => 'rascunho', self::STAGE_PRODUCTION => 'rascunho',
        self::STAGE_INTERNAL_REVIEW => 'revisao', self::STAGE_CLIENT_REVIEW => 'revisao',
        self::STAGE_SCHEDULED => 'publicada', self::STAGE_PUBLISHED => 'publicada', self::STAGE_ANALYSIS => 'otimizada',
    ];
    public const STATUS_TO_STAGE = [
        'rascunho' => self::STAGE_PLANNING, 'revisao' => self::STAGE_INTERNAL_REVIEW,
        'publicada' => self::STAGE_PUBLISHED, 'otimizada' => self::STAGE_ANALYSIS,
    ];

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
        'stage', 'stage_changed_at', 'changes_requested_at', 'current_version_id', 'approved_version_id', 'assignee_user_id',
    ];

    protected $casts = [
        'publish_date'  => 'date',
        'anchor_id'     => 'integer',
        'own_anchor_id' => 'integer',
        'blog_id'       => 'integer',
        'stage_changed_at' => 'datetime',
        'changes_requested_at' => 'datetime',
        'current_version_id' => 'integer',
        'approved_version_id' => 'integer',
    ];

    /** O estado antigo segue sempre a etapa. */
    protected static function booted(): void
    {
        static::saving(function (EditorialPost $post) {
            $post->stage ??= self::STAGE_PLANNING;
            $post->status = self::STAGE_TO_STATUS[$post->stage] ?? 'rascunho';
        });
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(EditorialPostVersion::class, 'current_version_id');
    }

    public function comments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EditorialPostComment::class);
    }

    public function versions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EditorialPostVersion::class)->orderByDesc('number');
    }

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
