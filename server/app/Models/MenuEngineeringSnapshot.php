<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** XPLENDOR — Fatia 3: um cálculo da engenharia de menu (período, loja, modo de preço). */
class MenuEngineeringSnapshot extends Model
{
    protected $fillable = ['company_id', 'period_from', 'period_to', 'location_id', 'price_mode', 'status', 'data', 'error', 'calculated_at'];

    protected $casts = ['data' => 'array', 'calculated_at' => 'datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(MenuEngineeringItem::class, 'snapshot_id');
    }

    /** O mais recente de uma empresa, loja e modo (o do período por omissão, se não se pedir outro). */
    public static function latestFor(int $companyId, ?int $locationId, string $mode, ?string $from = null, ?string $to = null): ?self
    {
        return self::where('company_id', $companyId)->where('price_mode', $mode)
            ->when($locationId === null, fn ($q) => $q->whereNull('location_id'), fn ($q) => $q->where('location_id', $locationId))
            ->when($from, fn ($q) => $q->whereDate('period_from', $from))->when($to, fn ($q) => $q->whereDate('period_to', $to))
            ->where('status', 'ok')->latest('calculated_at')->latest('id')->first();
    }
}
