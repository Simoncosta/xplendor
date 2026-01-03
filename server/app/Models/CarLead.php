<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarLead extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'message',
        'car_id',
        'company_id',
    ];
}
