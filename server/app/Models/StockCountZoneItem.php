<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountZoneItem extends Model
{
    protected $fillable = ['zone_id', 'product_id', 'position'];
}
