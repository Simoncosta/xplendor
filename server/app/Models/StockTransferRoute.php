<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — Um circuito de transferência (Fatia 7c): de um armazém para outro, com o documento de
 * saída, as moradas de carga e descarga e a receção automática (ou feita pelo restaurante).
 */
class StockTransferRoute extends Model
{
    protected $fillable = [
        'company_id', 'name', 'origin_warehouse_id', 'origin_store_id', 'dest_warehouse_id', 'dest_store_id', 'exit_docconfig_id',
        'auto_receive', 'load_address', 'unload_address', 'active', 'position',
    ];

    protected $attributes = ['exit_docconfig_id' => '1102', 'auto_receive' => true, 'active' => true, 'position' => 0];

    protected $casts = ['auto_receive' => 'boolean', 'active' => 'boolean', 'load_address' => 'array', 'unload_address' => 'array'];

    /** A morada está completa (o PingWin recusa a Guia sem morada de carga). */
    public static function addressComplete(?array $a): bool
    {
        return $a !== null && trim((string) ($a['address'] ?? '')) !== '' && trim((string) ($a['postalcode'] ?? '')) !== ''
            && trim((string) ($a['city'] ?? '')) !== '';
    }
}
