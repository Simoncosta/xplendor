<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Auditoria/estado de uma ESCRITA de condição de pagamento no PingWin
 * (Fatia 2a: criar), à imagem de PingwinCatalogWrite. Alvo de polling da UI enquanto
 * o worker executa. discount em % (não cêntimos); tbdocs_unlinked = docconfig_id
 * desmarcados.
 */
class PingwinPaycondWrite extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'action', 'paycond_id', 'code', 'description',
        'discount', 'days', 'tbdocs_unlinked', 'tbdocs_changes', 'status', 'pingwin_id', 'error_message', 'finished_at',
    ];

    protected $casts = [
        'discount'        => 'decimal:2',
        'days'            => 'integer',
        'tbdocs_unlinked' => 'array',
        'tbdocs_changes'  => 'array',
        'finished_at'     => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
