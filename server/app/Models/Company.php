<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

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
    ];

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }
}
