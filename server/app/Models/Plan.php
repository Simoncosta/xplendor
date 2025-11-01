<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price',
        'car_limit',
        'features',
    ];

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
