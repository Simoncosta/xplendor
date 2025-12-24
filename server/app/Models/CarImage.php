<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarImage extends Model
{
    protected $fillable = [
        'image',
        'is_primary',
        'order',
        'car_id',
        'company_id',
    ];
}
