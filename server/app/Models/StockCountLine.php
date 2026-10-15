<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** XPLENDOR — Uma linha de uma contagem: o que se contou (em várias unidades) e o que o PingWin disse. */
class StockCountLine extends Model
{
    protected $fillable = [
        'count_id', 'product_id', 'code', 'description', 'abc_class', 'zone_id', 'position', 'stock_unit', 'stock_unit_id', 'entries',
        'qty_base', 'counted_by', 'counted_at', 'recount_requested', 'previous_qty_base', 'theoretical_snapshot', 'snapshot_at',
        'unit_cost_snapshot', 'pingwin_unit_id', 'pingwin_theoretical_qnt', 'pingwin_unit_cost', 'pingwin_qnt_difference',
        'pingwin_price_difference', 'confirmed_qty', 'confirm_status',
    ];

    protected $casts = [
        'entries' => 'array', 'qty_base' => 'float', 'counted_at' => 'datetime', 'recount_requested' => 'boolean', 'previous_qty_base' => 'float',
        'theoretical_snapshot' => 'float', 'snapshot_at' => 'datetime', 'unit_cost_snapshot' => 'float', 'pingwin_theoretical_qnt' => 'float',
        'pingwin_unit_cost' => 'float', 'pingwin_qnt_difference' => 'float', 'pingwin_price_difference' => 'float', 'confirmed_qty' => 'float',
    ];
}
