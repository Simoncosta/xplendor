<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarDetail extends Model
{
    protected $fillable = [
        'detail',
        'field',
        'specification',
        'value',
        'car_id',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }
}
