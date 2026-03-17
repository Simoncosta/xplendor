<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarMarketingIdea extends Model
{
    protected $fillable = [
        'company_id',
        'car_id',
        'week_ref',
        'content_type',
        'status',
        'title',
        'angle',
        'goal',
        'target_audience',
        'formats',
        'caption',
        'hooks',
        'cta',
        'content_pillars',
        'why_now',
        'source_data',
        'ai_raw',
    ];

    protected $casts = [
        'week_ref' => 'date',
        'formats' => 'array',
        'hooks' => 'array',
        'content_pillars' => 'array',
        'source_data' => 'array',
    ];

    public function car()
    {
        return $this->belongsTo(\App\Models\Car::class);
    }
}
