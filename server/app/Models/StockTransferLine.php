<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferLine extends Model
{
    protected $fillable = [
        'transfer_id', 'request_line_id', 'product_id', 'code', 'description', 'qnt', 'unit_id', 'unit_desc', 'factor', 'qnt_base',
        'origin_pcm', 'last_purchase_price', 'price', 'price_confirmed', 'stock_before', 'received_qnt_base', 'sent_price', 'received_price',
    ];

    protected $casts = [
        'qnt' => 'float', 'factor' => 'float', 'qnt_base' => 'float', 'origin_pcm' => 'float', 'last_purchase_price' => 'float', 'price' => 'float',
        'price_confirmed' => 'boolean', 'stock_before' => 'float', 'received_qnt_base' => 'float', 'sent_price' => 'float', 'received_price' => 'float',
    ];
}
