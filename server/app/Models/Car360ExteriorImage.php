<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Car360ExteriorImage extends Model
{
    protected $table = 'car_360_exterior_images';
    protected $fillable = [
        'order',
        'image',
        'company_id',
        'car_id',
    ];
}
