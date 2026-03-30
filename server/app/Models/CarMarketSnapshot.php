<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarMarketSnapshot extends Model
{
    protected $fillable = [
        'external_id',
        'source',
        'brand',
        'model',
        'year',
        'title',
        'url',
        'category',
        'region',
        'price',
        'price_currency',
        'price_evaluation',
        'km',
        'fuel',
        'gearbox',
        'power_hp',
        'color',
        'doors',
        'scraped_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'price' => 'decimal:2',
        'km' => 'integer',
        'power_hp' => 'integer',
        'doors' => 'integer',
        'scraped_at' => 'datetime',
    ];
}
