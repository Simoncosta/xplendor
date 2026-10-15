<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * XPLENDOR — Uma linha (componente) de uma versão da ficha técnica. qnt_base = quantidade na
 * unidade base do componente; waste_pct e recovery_pct em %. is_bom = o componente é um
 * semi-produto (tem ficha própria). O custo do PingWin por armazém fica em costs.
 */
class PingwinProductBomLine extends Model
{
    protected $fillable = [
        'company_id', 'bom_pingwin_id', 'line_key', 'component_pingwin_id', 'component_code',
        'component_description', 'unit_pingwin_id', 'unit_desc', 'qnt', 'qnt_base', 'waste_pct',
        'recovery_pct', 'is_bom', 'component_status',
    ];

    protected $casts = [
        'qnt'              => 'float',
        'qnt_base'         => 'float',
        'waste_pct'        => 'float',
        'recovery_pct'     => 'float',
        'is_bom'           => 'boolean',
        'component_status' => 'integer',
    ];

    public function costs(): HasMany
    {
        return $this->hasMany(PingwinProductBomLineCost::class, 'bom_line_id');
    }
}
