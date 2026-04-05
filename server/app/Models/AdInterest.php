<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdInterest extends Model
{
    protected $fillable = [
        'meta_id',
        'name',
        'audience_size',
        'topic',
        'path',
        'is_active',
        'last_synced_at',
    ];

    protected $casts = [
        'path' => 'array',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}
