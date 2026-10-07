<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Vendas PingWin por loja × dia × artigo (relatório "Vendas por artigo").
 * Espelho: cada leitura SUBSTITUI os artigos do dia (ver PingwinItemSalesService).
 * Dinheiro em CÊNTIMOS inteiros; quantidade com 3 casas.
 */
class PingwinItemSale extends Model
{
    protected $table = 'pingwin_item_sales_daily';

    protected $fillable = [
        'company_id', 'location_id', 'business_date', 'product_pingwin_id', 'product_code', 'product_name',
        'family_pingwin_id', 'family_path', 'quantity', 'net_cents', 'tax_cents', 'gross_cents', 'synced_at',
    ];

    protected $casts = [
        // business_date fica como string 'Y-m-d' (sem cast date), como em PingwinDailySale.
        'quantity'    => 'float',
        'net_cents'   => 'integer',
        'tax_cents'   => 'integer',
        'gross_cents' => 'integer',
        'synced_at'   => 'datetime',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(PingwinLocation::class, 'location_id');
    }
}
