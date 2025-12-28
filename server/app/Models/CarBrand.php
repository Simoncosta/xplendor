<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarBrand extends Model
{
    protected $fillable = ['name', 'slug', 'logo'];

    public function models(): HasMany
    {
        return $this->hasMany(CarModel::class, 'car_brand_id');
    }
}
