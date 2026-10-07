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
        'content_sector_id', // Linha Editorial: o RAMO (setor-folha) da empresa
        'subscription_status',
        'trial_starts_at',
        'trial_ends_at',
        'subscription_ends_at',
        'cm_avg_ticket_enabled',
        'billing_reminders_enabled', // Cobranças da XPLENDOR: lembretes por email (desligado por omissão)
    ];

    protected function casts(): array
    {
        return [
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'archived_at' => 'datetime',
            'archive_delete_at' => 'datetime',
            'archive_warned_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'agency_enabled_at' => 'datetime',
            'uses_vat' => 'boolean',
            'content_approval_required' => 'boolean',
            'internal_review_required' => 'boolean',
            'cm_avg_ticket_enabled' => 'boolean',
            'billing_reminders_enabled' => 'boolean',
        ];
    }

    // "is_active" derivado — a ÚNICA definição de empresa ativa, partilhada pelo
    // rótulo na lista, pela exclusão do stock (scopeActive) e pelo guard de acesso
    // (CheckCompanySubscription usa hasPlatformAccess). Evita critérios divergentes.
    protected $appends = ['is_active'];

    /**
     * Token antigo da Meta (coluna legada, sem uso). Nunca é gravável por pedido
     * (fora do $fillable e da validação) nem sai nas respostas da API.
     */
    protected $hidden = ['facebook_access_token'];

    public function getIsActiveAttribute(): bool
    {
        return $this->hasPlatformAccess();
    }

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Linha Editorial: o RAMO (setor-folha) escolhido pela empresa (null = por escolher). */
    public function contentSector(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ContentSector::class, 'content_sector_id');
    }

    /** Gestão por agências: a relação ATIVA em que esta empresa é gerida (ou null). */
    public function activeManagement(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CompanyManagement::class, 'managed_company_id')->where('status', CompanyManagement::ACTIVE);
    }

    /** Todas as relações em que esta empresa foi (ou é) gerida: o histórico. */
    public function managements(): HasMany
    {
        return $this->hasMany(CompanyManagement::class, 'managed_company_id');
    }

    /** Subscrição própria paga e ativa: a própria empresa paga (a agência não paga por ela). */
    public function paysOwnSubscription(): bool
    {
        return $this->subscription_status === self::SUBSCRIPTION_STATUS_ACTIVE;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isAgency(): bool
    {
        return $this->agency_enabled_at !== null;
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
        return $query->whereNull('archived_at')->where(function ($q) {
            self::whereOwnAccess($q);
            // Empresa gerida: o acesso vem da agência (quem paga), nunca do período de teste dela.
            $q->orWhereHas('activeManagement.agency', fn ($a) => self::whereOwnAccess($a));
        });
    }

    private static function whereOwnAccess($q): void
    {
        $q->where(function ($o) {
            $o->where('subscription_status', self::SUBSCRIPTION_STATUS_ACTIVE)
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

    /**
     * Acesso à plataforma, calculado: a subscrição própria OU, numa empresa gerida, a
     * subscrição da agência gestora (quem paga). Uma empresa gerida nunca fica bloqueada
     * pelo período de teste dela.
     */
    public function hasPlatformAccess(): bool
    {
        // Arquivada (perdeu a agência e não tem admin): desativada até ser apagada ou sair do arquivo.
        if ($this->archived_at !== null) {
            return false;
        }
        if ($this->hasOwnPlatformAccess()) {
            return true;
        }
        $agency = $this->activeManagement?->agency;

        return $agency !== null && $agency->isAgency() && $agency->hasOwnPlatformAccess();
    }

    /** Só a subscrição própria (ativa, ou período de teste dentro do prazo). */
    public function hasOwnPlatformAccess(): bool
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
