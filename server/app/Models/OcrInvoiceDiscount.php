<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Um desconto do bloco de descontos de uma fatura OCR (ex.: "Leve Mais Pague Menos",
 * código 45238, 3,78 €, IVA código 5). Fica no artigo com o mesmo código de promoção; sem par,
 * fica "por atribuir" e a fatura não se lança. Valores em CÊNTIMOS.
 */
class OcrInvoiceDiscount extends Model
{
    public const ASSIGNED = 'atribuido';
    public const UNASSIGNED = 'por_atribuir';

    protected $fillable = [
        'ocr_invoice_id', 'company_id', 'position', 'code', 'label', 'amount_cents', 'vat_code', 'vat_rate',
        'status', 'allocations', 'warning',
    ];

    protected $casts = [
        'position'     => 'integer',
        'amount_cents' => 'integer',
        'vat_rate'     => 'integer',
        'allocations'  => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(OcrInvoice::class, 'ocr_invoice_id');
    }
}
