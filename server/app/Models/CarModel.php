<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarModel extends Model
{
    protected $fillable = ['name', 'type', 'vehicle_type', 'car_brand_id'];
}
