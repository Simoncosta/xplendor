<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — O armazém por omissão de uma loja, definido na XPLENDOR (Fatia 7a). Só existe quando o
 * PingWin não o diz sem ambiguidade (um só armazém a servir a loja).
 */
class RestaurantStoreWarehouse extends Model
{
    protected $fillable = ['company_id', 'store_pingwin_id', 'warehouse_pingwin_id', 'set_by'];
}
