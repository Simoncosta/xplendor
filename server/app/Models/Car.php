<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

class Car extends Model implements AuditableContract
{
    use Auditable;

    protected $fillable = [
        'status',
        'origin',
        'license_plate',
        'vin',
        'registration_month',
        'registration_year',
        'car_brand_id',
        'car_model_id',
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
    ];

    protected $casts = [
        'extras' => 'array',
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

    public function images(): HasMany
    {
        return $this->hasMany(CarImage::class);
    }

    public function car360ExteriorImages(): HasMany
    {
        return $this->hasMany(Car360ExteriorImage::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(CarView::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(CarLead::class);
    }

    public function analyses(): HasOne
    {
        return $this->hasOne(CarAiAnalysis::class);
    }
}
