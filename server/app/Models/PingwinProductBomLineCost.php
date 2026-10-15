<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** XPLENDOR — O custo do PingWin de uma linha da ficha num armazém ("" = todos os armazéns). */
class PingwinProductBomLineCost extends Model
{
    protected $fillable = ['bom_line_id', 'warehouse_pingwin_id', 'unit_cost', 'cost'];

    protected $casts = ['unit_cost' => 'float', 'cost' => 'float'];
}
