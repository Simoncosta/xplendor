<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarmineConnection extends Model
{
    protected $fillable = [
        'dealer_id',
        'token',
        'company_id',
    ];
}
