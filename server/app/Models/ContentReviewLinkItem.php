<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Publicação de um lote e a versão enviada ao cliente nesse lote. */
class ContentReviewLinkItem extends Model
{
    protected $fillable = ['content_review_link_id', 'editorial_post_id', 'version_id', 'position', 'urgent_alert_sent_at'];

    protected $casts = ['urgent_alert_sent_at' => 'datetime'];

    public function link(): BelongsTo
    {
        return $this->belongsTo(ContentReviewLink::class, 'content_review_link_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(EditorialPost::class, 'editorial_post_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(EditorialPostVersion::class, 'version_id');
    }
}
