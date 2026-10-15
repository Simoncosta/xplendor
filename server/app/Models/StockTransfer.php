<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * XPLENDOR — Um envio do Economato para um restaurante (Fatia 7c): a Guia (saída na origem) e a Entrada
 * (no destino). a_enviar → enviado → recebido (ou recebido_com_diferenca); falhou_envio, falhou_rececao;
 * anulado quando se anulam os documentos no PingWin.
 */
class StockTransfer extends Model
{
    public const STATUSES = ['a_enviar', 'enviado', 'recebido', 'recebido_com_diferenca', 'falhou_envio', 'falhou_rececao', 'anulado'];

    protected $fillable = [
        'company_id', 'route_id', 'request_id', 'origin_warehouse_id', 'origin_store_id', 'dest_warehouse_id', 'dest_store_id',
        'exit_docconfig_id', 'auto_receive', 'status', 'method', 'exit_doc_id', 'exit_doc_number', 'entry_doc_id', 'entry_doc_number',
        'total_value', 'note', 'error_message', 'created_by', 'sent_by', 'sent_at', 'received_by', 'received_at', 'voided_by', 'voided_at',
    ];

    protected $casts = [
        'auto_receive' => 'boolean', 'total_value' => 'float', 'sent_at' => 'datetime', 'received_at' => 'datetime', 'voided_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfer_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(StockTransferRoute::class, 'route_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockTransferRequest::class, 'request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
