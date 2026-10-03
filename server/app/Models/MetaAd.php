<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de anúncios Meta da empresa: nome actual, effective_status e o
 * resultado da tag [id:N] (ver App\Support\AdCarTag).
 */
class MetaAd extends Model
{
    public const TAG_UNTAGGED = 'untagged';
    public const TAG_MATCHED = 'matched';
    public const TAG_SPLIT = 'split';
    public const TAG_INVALID = 'invalid';

    /** Estados em que a empresa "usa tags" (incluindo tags mal escritas). */
    public const TAGGED_STATUSES = [self::TAG_MATCHED, self::TAG_SPLIT, self::TAG_INVALID];

    protected $table = 'meta_ads';

    protected $fillable = [
        'company_id', 'account_id', 'ad_id', 'ad_name', 'campaign_id', 'adset_id',
        'effective_status', 'status_synced_at',
        'tag_status', 'tag_car_ids', 'tag_invalid_ids', 'tag_evaluated_at',
    ];

    protected $casts = [
        'tag_car_ids'      => 'array',
        'tag_invalid_ids'  => 'array',
        'status_synced_at' => 'datetime',
        'tag_evaluated_at' => 'datetime',
    ];
}
