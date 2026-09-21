<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** XPLENDOR — Linha de uma fatura OCR (valores em CÊNTIMOS inteiros). */
class OcrInvoiceLine extends Model
{
    protected $fillable = [
        'ocr_invoice_id', 'company_id', 'position', 'item', 'quantity', 'unit',
        'unit_price_cents', 'discount_pct', 'line_total_cents', 'vat_rate',
    ];

    protected $casts = [
        'position'         => 'integer',
        'quantity'         => 'float',
        'unit_price_cents' => 'integer',
        'discount_pct'     => 'float',
        'line_total_cents' => 'integer',
        'vat_rate'         => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(OcrInvoice::class, 'ocr_invoice_id');
    }
}
