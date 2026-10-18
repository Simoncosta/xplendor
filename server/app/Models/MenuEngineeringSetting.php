<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** XPLENDOR — Fatia 3: os interruptores das exclusões automáticas da engenharia de menu (por empresa). */
class MenuEngineeringSetting extends Model
{
    public const TOGGLES = ['include_zero_price', 'include_low_volume', 'include_retired', 'include_uber_eats'];

    protected $fillable = ['company_id', 'include_zero_price', 'include_low_volume', 'include_retired', 'include_uber_eats', 'updated_by'];

    protected $casts = ['include_zero_price' => 'boolean', 'include_low_volume' => 'boolean', 'include_retired' => 'boolean', 'include_uber_eats' => 'boolean'];

    public static function for(int $companyId): self
    {
        return self::firstOrNew(['company_id' => $companyId], ['include_zero_price' => false, 'include_low_volume' => false, 'include_retired' => false, 'include_uber_eats' => false]);
    }
}
