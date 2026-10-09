<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * XPLENDOR — Documento de fornecedor do PingWin (lista "Documentos", DOCTYPE 2002).
 * UNIQUE (company_id, docheader_id); nunca se apaga (anulação = docstatus "Anulado").
 */
class PingwinSupplierDocument extends Model
{
    public const STATUS_ANULADO = '8003';

    protected $fillable = [
        'company_id', 'docheader_id', 'docconfig_id', 'doctype', 'document',
        'entity_pingwin_id', 'entity_name', 'fiscalname', 'tax_number', 'supplier_id',
        'store_pingwin_id', 'store_name', 'doc_date', 'fiscal_date', 'doc_time',
        'total_cents', 'paid', 'docstatus_id', 'docstatus_description', 'employee_name',
        'docreference_number', 'raw', 'first_seen_at', 'last_seen_at',
        // F4: header lido no documento + estado da leitura das linhas
        'due_date', 'paycond_pingwin_id', 'docreference_date', 'total_products_cents', 'total_tax_cents',
        'detail_discount_cents', 'adjustment_cents', 'discount1', 'discount2', 'shipping_cents', 'withholding_cents', 'lines_synced_at', 'lines_status',
        'lines_error', 'lines_check', 'lines_diff_cents', 'lines_tax_diff_cents', 'lines_synced_total_cents',
        'lines_synced_docstatus_id',
    ];

    protected $casts = [
        'doc_date'      => 'date',
        'fiscal_date'   => 'date',
        'total_cents'   => 'integer',
        'paid'          => 'boolean',
        'raw'           => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
        'due_date'          => 'date',
        'docreference_date' => 'date',
        'lines_synced_at'   => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PingwinSupplierDocumentLine::class, 'docheader_id', 'docheader_id')
            ->where('company_id', $this->company_id)->orderBy('line_number');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PingwinSupplier::class, 'supplier_id');
    }
}
