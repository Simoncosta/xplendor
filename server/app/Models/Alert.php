<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $fillable = [
        'company_id',
        'car_id',
        'type',
        'title',
        'message',
        'detail_path',
        'severity',
        'is_read',
        'own_only',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'own_only' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }
}
