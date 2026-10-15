<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * XPLENDOR — Um pedido de um restaurante (Fatia 7c): uma encomenda ENCFB/ENCF lida do PingWin, ou uma
 * requisição interna antiga (só consulta: kind = requisicao).
 */
class StockTransferRequest extends Model
{
    protected $fillable = [
        'company_id', 'pingwin_doc_id', 'docconfig_id', 'kind', 'document', 'store_id', 'store_name', 'entity_id', 'entity_name', 'doc_date',
        'docstatus_id', 'employee_name', 'total', 'converted', 'route_id', 'lines_status', 'lines_error', 'synced_at',
    ];

    protected $casts = ['converted' => 'boolean', 'total' => 'float', 'synced_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferRequestLine::class, 'request_id')->orderBy('line_number');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(StockTransferRoute::class, 'route_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'request_id');
    }
}
