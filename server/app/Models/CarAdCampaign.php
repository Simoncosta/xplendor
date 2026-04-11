<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarAdCampaign extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $mapping) {
            $mapping->level = $mapping->inferLevel();
        });
    }

    protected $fillable = [
        'company_id',
        'car_id',
        'platform',
        'campaign_id',
        'campaign_name',
        'adset_id',
        'adset_name',
        'ad_id',
        'ad_name',
        'level',
        'spend_split_pct',
        'is_active',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'spend_split_pct' => 'decimal:2',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // O ID externo relevante consoante o nível de mapeamento
    public function getExternalIdAttribute(): string
    {
        return (string) ($this->ad_id ?? $this->adset_id ?? $this->campaign_id);
    }

    public function getResolvedLevelAttribute(): string
    {
        return $this->inferLevel();
    }

    public function inferLevel(): string
    {
        if (!empty($this->ad_id)) {
            return 'ad';
        }

        if (!empty($this->adset_id)) {
            return 'adset';
        }

        return 'campaign';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }
}
