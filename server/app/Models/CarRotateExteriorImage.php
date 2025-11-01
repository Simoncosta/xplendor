<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarRotateExteriorImage extends Model
{
    protected $fillable = [
        'image',
        'order',
        'car_id',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }
}
