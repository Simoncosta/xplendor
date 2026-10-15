<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** XPLENDOR — A fotografia diária do stock de um artigo num armazém (Fatia 7a). */
class PingwinStockSnapshot extends Model
{
    protected $fillable = ['company_id', 'snapshot_date', 'product_pingwin_id', 'warehouse_pingwin_id', 'qnt', 'pcm', 'pcu'];

    // A data fica como texto (Y-m-d): procura-se e grava-se pela data, igual em MySQL e em sqlite.
    protected $casts = ['qnt' => 'float', 'pcm' => 'float', 'pcu' => 'float'];
}
