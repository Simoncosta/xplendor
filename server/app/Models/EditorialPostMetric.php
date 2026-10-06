<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um número de resultado de uma publicação, com a ORIGEM (manual agora; "meta" quando a
 * F6 os ler automaticamente) e a data da medição. Um por métrica e por origem.
 */
class EditorialPostMetric extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_META = 'meta';

    /** Métricas, pela ordem do ecrã. As visualizações só nos vídeos. */
    public const METRICS = ['reach', 'interactions', 'likes', 'comments', 'saves', 'shares', 'clicks', 'video_views'];

    public const VIDEO_FORMATS = ['ig_reel', 'fb_video', 'fb_reel'];

    protected $fillable = ['company_id', 'editorial_post_id', 'network', 'metric', 'source', 'value', 'measured_on', 'recorded_by_user_id', 'impersonator_user_id'];

    protected $casts = ['value' => 'integer', 'measured_on' => 'date'];
}
