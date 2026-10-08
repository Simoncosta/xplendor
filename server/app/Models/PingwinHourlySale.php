<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — F2: vendas PingWin por loja × dia × hora (valor sem IVA), espelho das "Vendas
 * por hora". Cada leitura substitui as horas do dia (PingwinHourlySalesService).
 */
class PingwinHourlySale extends Model
{
    protected $fillable = ['company_id', 'location_id', 'business_date', 'hour', 'net_cents', 'synced_at'];

    protected $casts = [
        // business_date fica como string 'Y-m-d' (sem cast date), como em PingwinDailySale.
        'hour' => 'integer',
        'net_cents' => 'integer',
        'synced_at' => 'datetime',
    ];
}
