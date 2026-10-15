<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * XPLENDOR — Uma versão da ficha técnica de um artigo no PingWin (Fatia 1, só leitura). A versão em
 * vigor numa data D e numa loja L é a de valid_from mais recente ≤ D, não anulada, com L ativa.
 */
class PingwinProductBom extends Model
{
    protected $fillable = [
        'company_id', 'product_pingwin_id', 'bom_pingwin_id', 'version_key', 'valid_from', 'deleted', 'synced_at',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'deleted'    => 'boolean',
        'synced_at'  => 'datetime',
    ];

    public function stores(): HasMany
    {
        return $this->hasMany(PingwinProductBomStore::class, 'bom_pingwin_id', 'bom_pingwin_id')
            ->where('pingwin_product_bom_stores.company_id', $this->company_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PingwinProductBomLine::class, 'bom_pingwin_id', 'bom_pingwin_id')
            ->where('pingwin_product_bom_lines.company_id', $this->company_id);
    }
}
