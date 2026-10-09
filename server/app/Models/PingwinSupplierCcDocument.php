<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Documento de conta corrente de fornecedor (espelho do PingWin,
 * suppliercc.ccdocuments). UNIQUE (company_id, docheader_id); valores em cêntimos.
 * ca_signal 1 = fatura/crédito (aumenta a dívida); -1 = liquidação/NC (abate).
 */
class PingwinSupplierCcDocument extends Model
{
    protected $fillable = [
        'company_id', 'supplier_id', 'entity_pingwin_id', 'docheader_id', 'docconfig_id',
        'doctype', 'document', 'doc_date', 'due_date', 'fiscal_date',
        'total_cents', 'total_paid_cents', 'topay_cents', 'suspended_cents',
        'paid', 'ca_signal', 'iscredit', 'isdebit', 'docstatus_description',
        'docreference_number', 'store_pingwin_id', 'store_name', 'raw', 'synced_at',
    ];

    protected $casts = [
        'doc_date'         => 'date',
        'due_date'         => 'date',
        'fiscal_date'      => 'date',
        'total_cents'      => 'integer',
        'total_paid_cents' => 'integer',
        'topay_cents'      => 'integer',
        'suspended_cents'  => 'integer',
        'paid'             => 'boolean',
        'ca_signal'        => 'integer',
        'iscredit'         => 'boolean',
        'isdebit'          => 'boolean',
        'raw'              => 'array',
        'synced_at'        => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PingwinSupplier::class, 'supplier_id');
    }
}
