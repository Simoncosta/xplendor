<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarView extends Model
{
    protected $fillable = [
        'company_id',
        'car_id',
        'user_id',
        'ip_address',
        'user_agent'
    ];
}
