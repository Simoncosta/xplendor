<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — F3: uma ligação Fatura OCR → documento de fornecedor do PingWin (espelho).
 * Uma fatura pode ter N (fatura de guias). method: numero | total_data | guias | manual.
 */
class OcrInvoicePingwinLink extends Model
{
    public const NUMBER = 'numero';
    public const TOTAL_DATE = 'total_data';
    public const GUIDES = 'guias';
    public const MANUAL = 'manual';

    protected $fillable = ['company_id', 'ocr_invoice_id', 'docheader_id', 'method', 'confirmed_by', 'confirmed_at'];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(OcrInvoice::class, 'ocr_invoice_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PingwinSupplierDocument::class, 'docheader_id', 'docheader_id')
            ->where('pingwin_supplier_documents.company_id', $this->company_id);
    }
}
