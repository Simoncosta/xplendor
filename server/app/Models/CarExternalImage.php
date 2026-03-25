<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarExternalImage extends Model
{
    protected $fillable = [
        'car_id',
        'company_id',
        'source',
        'external_url',
        'external_index',
        'is_primary',
        'sort_order',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
