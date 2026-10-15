<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferRequestLine extends Model
{
    protected $fillable = [
        'request_id', 'line_number', 'line_pingwin_id', 'product_id', 'code', 'description', 'qnt', 'unit_id', 'unit_desc', 'unit_value',
        'qnt_base', 'qnt_delivered', 'price', 'warehouse_id', 'src_line_pingwin_id',
    ];

    protected $casts = ['qnt' => 'float', 'unit_value' => 'float', 'qnt_base' => 'float', 'qnt_delivered' => 'float', 'price' => 'float'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockTransferRequest::class, 'request_id');
    }
}
