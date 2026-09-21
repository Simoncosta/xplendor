<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * XPLENDOR — Fatura de fornecedor lida por OCR (Fase A, validação humana). NÃO
 * escreve no PingWin (synced_to_pingwin fica false até à Fase B). Guarda
 * model + prompt_version para depurar o prompt.
 */
class OcrInvoice extends Model
{
    protected $fillable = [
        'company_id', 'supplier_id', 'supplier_name', 'supplier_nif', 'number', 'issue_date',
        'image_path', 'image_mime', 'model', 'prompt_version', 'confidence', 'status',
        'error_message', 'synced_to_pingwin',
    ];

    protected $casts = [
        'issue_date'        => 'date',
        'confidence'        => 'integer',
        'synced_to_pingwin' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PingwinSupplier::class, 'supplier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OcrInvoiceLine::class)->orderBy('position');
    }

    public function summary(): HasOne
    {
        return $this->hasOne(OcrInvoiceSummary::class);
    }
}
