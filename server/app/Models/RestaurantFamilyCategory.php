<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — F1-3: categoria de marketing de uma família do PingWin. suggested_* vem das
 * regras ou da IA; category só é preenchida quando a equipa confirma.
 */
class RestaurantFamilyCategory extends Model
{
    protected $fillable = [
        'company_id', 'family_pingwin_id', 'family_path', 'suggested_category', 'suggested_by',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
    ];
}
