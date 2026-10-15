<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** XPLENDOR — Uma zona de contagem de um armazém (Fatia 7b): os artigos, pela ordem em que se contam. */
class StockCountZone extends Model
{
    protected $fillable = ['company_id', 'warehouse_id', 'name', 'position'];

    public function items(): HasMany
    {
        return $this->hasMany(StockCountZoneItem::class, 'zone_id')->orderBy('position');
    }
}
