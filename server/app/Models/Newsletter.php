<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Newsletter extends Model
{
    protected $fillable = [
        'name',
        'email',
        'is_subscribed',
        'subscribed_at',
        'unsubscribed_at',
        'source',
        'ip_address',
        'company_id',
    ];
}
