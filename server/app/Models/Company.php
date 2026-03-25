<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    public const SUBSCRIPTION_STATUS_TRIAL = 'trial';
    public const SUBSCRIPTION_STATUS_ACTIVE = 'active';
    public const SUBSCRIPTION_STATUS_EXPIRED = 'expired';
    public const SUBSCRIPTION_STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'nipc',
        'fiscal_name',
        'slug',
        'trade_name',
        'responsible_name',
        'address',
        'postal_code',
        'district_id',
        'municipality_id',
        'parish_id',
        'phone',
        'mobile',
        'email',
        'invoice_email',
        'registry_office',
        'registry_office_number',
        'capital_social',
        'nib',
        'registration_fees',
        'export_promotion_price',
        'credit_intermediation_link',
        'vat_value',
        'facebook_page_id',
        'facebook_pixel_id',
        'facebook_access_token',
        'website',
        'instagram',
        'youtube',
        'facebook',
        'google',
        'lead_hours_pending',
        'lead_distribution',
        'ad_text',
        'pdf_path',
        'logo_path',
        'banner_path',
        'carmine_logo_path',
        'public_api_token',
        'plan_id',
        'subscription_status',
        'trial_starts_at',
        'trial_ends_at',
        'subscription_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
        ];
    }

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

    public function carExternalImages(): HasMany
    {
        return $this->hasMany(CarExternalImage::class);
    }

    public function initializeTrial(int $days = 30): void
    {
        $startsAt = now();

        $this->subscription_status = self::SUBSCRIPTION_STATUS_TRIAL;
        $this->trial_starts_at = $startsAt;
        $this->trial_ends_at = (clone $startsAt)->addDays($days);
        $this->subscription_ends_at = null;
    }

    public function isTrialExpired(): bool
    {
        return $this->subscription_status === self::SUBSCRIPTION_STATUS_TRIAL
            && $this->trial_ends_at instanceof Carbon
            && $this->trial_ends_at->isPast();
    }

    public function hasPlatformAccess(): bool
    {
        if ($this->subscription_status === self::SUBSCRIPTION_STATUS_ACTIVE) {
            return true;
        }

        if ($this->subscription_status !== self::SUBSCRIPTION_STATUS_TRIAL) {
            return false;
        }

        if (!$this->trial_ends_at instanceof Carbon) {
            return false;
        }

        return $this->trial_ends_at->isFuture() || $this->trial_ends_at->isNow();
    }
}
