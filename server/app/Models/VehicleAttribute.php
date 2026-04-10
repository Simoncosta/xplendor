<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleAttribute extends Model
{
    protected $table = 'vehicle_attributes';

    protected $fillable = [
        'car_id',
        'attributes',
    ];

    protected $casts = [
        'attributes' => 'array',
    ];
}
