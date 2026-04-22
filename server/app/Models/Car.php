<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

class Car extends Model implements AuditableContract
{
    use Auditable, HasFactory;

    protected $fillable = [
        'status',
        'sold_at',
        'is_resume',
        'origin',
        'license_plate',
        'vin',
        'registration_month',
        'registration_year',
        'car_brand_id',
        'car_model_id',
        'vehicle_type',
        'subsegment',
        'car_category_id',
        'version',
        'public_version_name',
        'fuel_type',
        'power_hp',
        'engine_capacity_cc',
        'doors',
        'transmission',
        'segment',
        'seats',
        'exterior_color',
        'is_metallic',
        'interior_color',
        'condition',
        'mileage_km',
        'co2_emissions',
        'toll_class',
        'cylinders',
        'warranty_available',
        'warranty_due_date',
        'warranty_km',
        'service_records',
        'has_spare_key',
        'has_manuals',
        'price_gross',
        'promo_price_gross',
        'price_net',
        'hide_price_online',
        'monthly_payment',
        'extras',
        'lifestyle',
        'description_website_pt',
        'description_website_en',
        'internal_notes',
        'youtube_url',
        'company_id',
        'seller_user_id',
        'carmine_id',
        'car_created_at',
    ];

    protected $casts = [
        'extras' => 'array',
        'car_created_at' => 'datetime',
        'sold_at' => 'datetime',
        'subsegment' => 'string',
    ];

    protected $appends = [
        'has_promo_price',
        'promo_discount_value',
        'promo_discount_pct',
        'vehicle_attributes',
    ];

    public function getExtrasAttribute($value)
    {
        $extras = json_decode($value, true) ?? [];

        // Lista dos grupos padrão
        $defaultGroups = [
            'comfort_multimedia',
            'exterior_equipment',
            'interior_equipment',
            'safety_performance',
        ];

        // Indexa os grupos salvos para facilitar merge
        $indexed = collect($extras)->keyBy('group');

        // Garante retorno com todos os grupos
        return collect($defaultGroups)->map(function ($group) use ($indexed) {
            return [
                'group' => $group,
                'items' => $indexed[$group]['items'] ?? [],
            ];
        })->toArray();
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(CarBrand::class, 'car_brand_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(CarModel::class, 'car_model_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CarCategory::class, 'car_category_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(CarImage::class);
    }

    public function externalImages(): HasMany
    {
        return $this->hasMany(CarExternalImage::class)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function car360ExteriorImages(): HasMany
    {
        return $this->hasMany(Car360ExteriorImage::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(CarView::class, 'car_id', 'id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(CarLead::class, 'car_id', 'id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(CarInteraction::class, 'car_id', 'id');
    }

    public function whatsappInteractions(): HasMany
    {
        return $this->hasMany(CarInteraction::class)
            ->where('interaction_type', 'whatsapp_click');
    }

    public function callInteractions(): HasMany
    {
        return $this->hasMany(CarInteraction::class)
            ->where('interaction_type', 'call_click');
    }

    public function analyses(): HasOne
    {
        return $this->hasOne(CarAiAnalysis::class);
    }

    public function vehicleAttribute(): HasOne
    {
        return $this->hasOne(VehicleAttribute::class, 'car_id');
    }

    public function getVehicleAttributesAttribute(): ?array
    {
        $vehicleAttribute = $this->relationLoaded('vehicleAttribute')
            ? $this->getRelation('vehicleAttribute')
            : $this->vehicleAttribute()->first();

        if (!$vehicleAttribute) {
            return null;
        }

        $attributes = $vehicleAttribute->getAttribute('attributes');

        return is_array($attributes) ? $attributes : null;
    }

    public function sale(): HasOne
    {
        return $this->hasOne(CarSale::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function marketingIdeas(): HasMany
    {
        return $this->hasMany(CarMarketingIdea::class);
    }

    public function adCampaigns(): HasMany
    {
        return $this->hasMany(CarAdCampaign::class, 'car_id');
    }

    public function adAttributions(): HasMany
    {
        return $this->hasMany(CarAdAttribution::class, 'car_id');
    }

    public function salesLearning(): HasMany
    {
        return $this->hasMany(CarSalesLearning::class, 'car_id');
    }

    public function saleAttributions(): HasMany
    {
        return $this->hasMany(CarSaleAttribution::class, 'car_id');
    }

    public function getPromoDiscountValueAttribute(): ?float
    {
        $priceGross = $this->price_gross;
        $promoPriceGross = $this->promo_price_gross;

        if ($priceGross === null || $promoPriceGross === null || $promoPriceGross >= $priceGross) {
            return null;
        }

        return round((float) $priceGross - (float) $promoPriceGross, 2);
    }

    public function getHasPromoPriceAttribute(): bool
    {
        $priceGross = $this->price_gross;
        $promoPriceGross = $this->promo_price_gross;

        return $promoPriceGross !== null
            && (float) $promoPriceGross > 0
            && $priceGross !== null
            && (float) $promoPriceGross < (float) $priceGross;
    }

    public function getPromoDiscountPctAttribute(): ?float
    {
        $discountValue = $this->promo_discount_value;
        $priceGross = $this->price_gross;

        if (!$this->has_promo_price || $discountValue === null || empty($priceGross) || (float) $priceGross <= 0) {
            return null;
        }

        return round(($discountValue / (float) $priceGross) * 100, 2);
    }
}
