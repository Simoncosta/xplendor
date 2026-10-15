<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — O que a leitura do stock disse de um artigo (Fatia 7a): a configuração de stock e se
 * mexe stock (o catálogo não a traz), a unidade de stock, a última leitura e o último erro.
 */
class PingwinStockItem extends Model
{
    protected $fillable = [
        'company_id', 'product_pingwin_id', 'stockconfig_id', 'stockconfig', 'movestock', 'stock_unit_id', 'stock_unit',
        'last_read_at', 'last_error',
    ];

    protected $casts = ['movestock' => 'boolean', 'last_read_at' => 'datetime'];
}
