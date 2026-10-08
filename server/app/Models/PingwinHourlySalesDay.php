<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — F2: estado de cada loja × dia lido das "Vendas por hora" (a soma das horas
 * conferida com o líquido diário). Os estados são os de PingwinItemSalesDay.
 */
class PingwinHourlySalesDay extends Model
{
    protected $fillable = [
        'company_id', 'location_id', 'business_date', 'status', 'rows_count',
        'hours_net_cents', 'daily_net_cents', 'reads_count', 'synced_at',
    ];

    protected $casts = [
        'rows_count' => 'integer',
        'hours_net_cents' => 'integer',
        'daily_net_cents' => 'integer',
        'reads_count' => 'integer',
        'synced_at' => 'datetime',
    ];
}
