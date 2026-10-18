<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Site do consultor: um host que serve o site (o principal é o canónico). Só o root os muda. */
class ConsultantSiteHost extends Model
{
    protected $fillable = ['consultant_site_id', 'host', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(ConsultantSite::class, 'consultant_site_id');
    }

    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host; // sem a porta

        return rtrim($host, '.');
    }
}
