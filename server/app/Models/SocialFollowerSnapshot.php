<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seguidores de uma rede num dia (Instagram ou Página de Facebook). Uma linha por
 * empresa, plataforma e dia; a leitura automática ganha à manual.
 */
class SocialFollowerSnapshot extends Model
{
    public const PLATFORM_INSTAGRAM = 'instagram';
    public const PLATFORM_FACEBOOK = 'facebook';
    public const PLATFORMS = [self::PLATFORM_INSTAGRAM, self::PLATFORM_FACEBOOK];

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_API = 'api';
    public const SOURCE_BUSINESS_DISCOVERY = 'business_discovery';

    protected $fillable = [
        'company_id', 'platform', 'snapshot_date', 'followers_count', 'follows_count',
        'media_count', 'source', 'recorded_by_user_id',
    ];

    protected $casts = [
        'snapshot_date' => 'date:Y-m-d',
        'followers_count' => 'integer',
        'follows_count' => 'integer',
        'media_count' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isAutomatic(): bool
    {
        return $this->source !== self::SOURCE_MANUAL;
    }
}
