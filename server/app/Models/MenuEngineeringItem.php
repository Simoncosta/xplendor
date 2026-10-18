<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuEngineeringItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'detail' => 'array', 'qty' => 'float', 'price' => 'float', 'cost' => 'float', 'margin' => 'float', 'margin_total' => 'float',
        'food_cost_pct' => 'float', 'share_pct' => 'float', 'revenue_share_pct' => 'float', 'popular' => 'boolean', 'high_margin' => 'boolean', 'fragile' => 'boolean',
    ];
}
