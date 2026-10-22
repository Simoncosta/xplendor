<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um consentimento aceite na página de envio: o aviso de proteção de dados ("privacidade") ou a
 * cópia do Cartão de Cidadão ("copia_cc", Lei 7/2007, art. 5.º), com o texto integral mostrado,
 * a versão e a data. Sem IP nem navegador.
 */
class FinancingConsent extends Model
{
    public const PRIVACY = 'privacidade';
    public const CC_COPY = 'copia_cc';

    public $timestamps = false;

    protected $fillable = ['financing_link_id', 'kind', 'text', 'version', 'accepted_at', 'signed_name'];

    protected $casts = ['accepted_at' => 'datetime'];

    public function link(): BelongsTo
    {
        return $this->belongsTo(FinancingLink::class, 'financing_link_id');
    }
}
