<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — O stock de um artigo num armazém, tal como o PingWin o mostra (tbstock, Fatia 7a):
 * quantidade, PCM (custo médio), PCU (último custo), última valorização, mínimo e máximo.
 */
class PingwinStock extends Model
{
    protected $table = 'pingwin_stock';

    protected $fillable = [
        'company_id', 'product_pingwin_id', 'warehouse_pingwin_id', 'unit_name', 'unit_value', 'qnt', 'pcm', 'pcu',
        'valorization_date', 'min_stock', 'max_stock', 'disable_generic', 'qnt_pend_cli', 'qnt_pend_sup', 'synced_at',
    ];

    protected $casts = [
        'unit_value' => 'float',
        'qnt' => 'float',
        'pcm' => 'float',
        'pcu' => 'float',
        'valorization_date' => 'date',
        'min_stock' => 'float',
        'max_stock' => 'float',
        'disable_generic' => 'boolean',
        'qnt_pend_cli' => 'float',
        'qnt_pend_sup' => 'float',
        'synced_at' => 'datetime',
    ];
}
