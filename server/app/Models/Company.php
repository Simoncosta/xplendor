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
        'uses_vat',
        'facebook_page_id',
        'facebook_pixel_id',
        'facebook_access_token',
        'website',
        'instagram',
        'youtube',
        'facebook',
        'google',
        'google_review_url',
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
        'cm_avg_ticket_enabled',
    ];

    protected function casts(): array
    {
        return [
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'uses_vat' => 'boolean',
            'cm_avg_ticket_enabled' => 'boolean',
        ];
    }

    // "is_active" derivado — a ÚNICA definição de empresa ativa, partilhada pelo
    // rótulo na lista, pela exclusão do stock (scopeActive) e pelo guard de acesso
    // (CheckCompanySubscription usa hasPlatformAccess). Evita critérios divergentes.
    protected $appends = ['is_active'];

    public function getIsActiveAttribute(): bool
    {
        return $this->hasPlatformAccess();
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

    /**
     * Scope: empresas ATIVAS (com acesso à plataforma). Espelha em SQL a
     * definição de hasPlatformAccess(): subscription 'active', OU 'trial' ainda
     * dentro do prazo. Empresas arquivadas (soft-deleted) já saem pelo global
     * scope do SoftDeletes. Fonte única para as vistas transversais do /admin
     * (ex.: Stock global) — futuras consolas reutilizam este scope.
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('subscription_status', self::SUBSCRIPTION_STATUS_ACTIVE)
                ->orWhere(function ($t) {
                    $t->where('subscription_status', self::SUBSCRIPTION_STATUS_TRIAL)
                        ->where('trial_ends_at', '>=', now());
                });
        });
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
