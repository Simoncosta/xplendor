<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarLead extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'message',
        'car_id',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }
}
