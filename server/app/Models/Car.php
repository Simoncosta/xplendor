<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Car extends Model
{
    protected $fillable = [
        'status',
        'origin',
        'license_plate',
        'vin',
        'registration_month',
        'registration_year',
        'brand',
        'model',
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

    public function images(): HasMany
    {
        return $this->hasMany(CarImage::class);
    }

    public function car360ExteriorImages(): HasMany
    {
        return $this->hasMany(Car360ExteriorImage::class);
    }
}
