<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Car extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'status',
        'is_imported',
        'licence_plate',
        'km',
        'vin',
        'month_registration',
        'year_registration',
        'mark',
        'model',
        'fuel',
        'power',
        'number_doors',
        'gearbox',
        'version',
        'segment',
        'color',
        'link_youtube',
        'description',
        'price',
        'show_price',
        'discount_type',
        'discount',
        'company_id',
        'seller_id',
        'created_by_id',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(CarView::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(CarLead::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CarLog::class);
    }

    public function rotateExteriorImages(): HasMany
    {
        return $this->hasMany(CarRotateExteriorImage::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(CarImage::class);
    }

    public function detail(): HasMany
    {
        return $this->hasMany(CarDetail::class);
    }
}
