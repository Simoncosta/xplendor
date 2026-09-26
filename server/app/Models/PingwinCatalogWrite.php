<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Auditoria/estado de uma ESCRITA de artigo no PingWin, à imagem de
 * PingwinUnitCreation. Regista quem/quando/o quê + o resultado (pingwin_id ou erro
 * real). É também o alvo de polling da UI enquanto o worker executa a escrita.
 *
 * ⚠️ Preparado para a Etapa 1b (create/edit/anular). Este incremento é só leitura.
 */
class PingwinCatalogWrite extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'action', 'catalog_item_id', 'code', 'description',
        'payload', 'saleprice_cents', 'purchaseprice_cents', 'supplier_prices_changes',
        'status', 'pingwin_id', 'error_message', 'finished_at',
    ];

    protected $casts = [
        'payload'                 => 'array',
        'saleprice_cents'         => 'integer',
        'purchaseprice_cents'     => 'integer',
        'supplier_prices_changes' => 'array',
        'finished_at'             => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
