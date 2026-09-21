<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** XPLENDOR — Sumário (1-1) de uma fatura OCR: a cadeia B2B em CÊNTIMOS. */
class OcrInvoiceSummary extends Model
{
    protected $table = 'ocr_invoice_summary';

    protected $fillable = [
        'ocr_invoice_id', 'company_id', 'goods_total_cents', 'commercial_discount_cents',
        'taxable_base_cents', 'vat_total_cents', 'withholding_cents', 'financial_discount_cents',
        'total_cents', 'vat_breakdown',
    ];

    protected $casts = [
        'goods_total_cents'         => 'integer',
        'commercial_discount_cents' => 'integer',
        'taxable_base_cents'        => 'integer',
        'vat_total_cents'           => 'integer',
        'withholding_cents'         => 'integer',
        'financial_discount_cents'  => 'integer',
        'total_cents'               => 'integer',
        'vat_breakdown'             => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(OcrInvoice::class, 'ocr_invoice_id');
    }
}
