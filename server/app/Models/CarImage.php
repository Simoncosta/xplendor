<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarImage extends Model
{
    protected $fillable = [
        'image',
        'is_primary',
        'car_id',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function getImageAttribute($value): string
    {
        return asset($value);
    }
}
