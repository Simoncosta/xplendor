<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'vehicle_type',
    ];
}
