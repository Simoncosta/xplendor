<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma abertura real do link de aprovação (sinal enviado pela página depois de carregar).
 * Sem IP: só um identificador aleatório do browser em hash, o tipo de dispositivo e as datas.
 */
class ContentReviewLinkOpen extends Model
{
    public $timestamps = false;

    protected $fillable = ['content_review_link_id', 'visitor_hash', 'device', 'opened_at', 'last_seen_at'];

    protected $hidden = ['visitor_hash'];

    protected $casts = ['opened_at' => 'datetime', 'last_seen_at' => 'datetime'];
}
