<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Auditoria/estado de uma criação de unidade no PingWin (1ª escrita).
 * Regista quem/quando/o quê + o resultado (pingwin_id ou erro real). É também o
 * alvo de polling da UI enquanto o worker executa a escrita.
 */
class PingwinUnitCreation extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'action', 'unit_id', 'description', 'shortname', 'parent_pingwin_id',
        'parent_qnt', 'net_weight', 'external_measure', 'frac_unit', 'warn_maxsale_qnt', 'status', 'pingwin_id',
        'error_message', 'finished_at',
    ];

    protected $casts = [
        'parent_qnt'       => 'float',
        'net_weight'       => 'float',
        'frac_unit'        => 'boolean',
        'warn_maxsale_qnt' => 'float',
        'finished_at'      => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
