<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Um pagamento registado na XPLENDOR (enquanto não há nota de liquidação no PingWin): data,
 * forma e referência. Desfeito, fica no histórico (undone_at). Quando a nota do PingWin aparece na
 * conta corrente, ganha a este registo.
 */
class FinancePayment extends Model
{
    public const METHODS = [
        'transferencia' => 'Transferência', 'cartao_debito' => 'Cartão de débito', 'cartao_credito' => 'Cartão de crédito',
        'numerario' => 'Numerário', 'debito_direto' => 'Débito direto', 'outro' => 'Outro',
    ];

    protected $fillable = ['company_id', 'batch', 'docheader_id', 'entity_pingwin_id', 'amount_cents', 'paid_on', 'method', 'reference', 'created_by', 'undone_at', 'undone_by'];

    protected $casts = ['amount_cents' => 'integer', 'paid_on' => 'date', 'undone_at' => 'datetime'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
