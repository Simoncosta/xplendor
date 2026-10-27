<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * XPLENDOR — Fatura de fornecedor lida por OCR (Fase A, validação humana). NÃO
 * escreve no PingWin (synced_to_pingwin fica false até à Fase B). Guarda
 * model + prompt_version para depurar o prompt.
 */
class OcrInvoice extends Model
{
    // F2c: apagar = soft delete (sai das listas e dos jobs; repõe-se enquanto o ficheiro existir).
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'supplier_id', 'supplier_name', 'supplier_nif', 'number', 'issue_date',
        'image_path', 'image_size_bytes', 'image_mime', 'model', 'ai_provider', 'ai_effort', 'prompt_version', 'confidence', 'status',
        'error_message', 'synced_to_pingwin',
        // F2a: QR + origem + custo + conferência
        'qr_raw', 'qr_ok', 'qr_data', 'buyer_nif', 'atcud', 'doc_type', 'source', 'lines_source', 'pages',
        'tokens_in', 'tokens_out', 'cost_usd', 'duration_ms', 'attempts', 'attempts_log', 'check_status', 'check_diff',
        // F3: ligação ao PingWin
        'link_status', 'link_diff_cents', 'link_checked_at', 'link_note', 'link_candidates', 'link_rejected',
        'link_search_pending', 'duplicate_of_id', 'guide_refs',
        // FB-1: diferença das linhas face ao QR aceite pela pessoa
        'check_accepted_by', 'check_accepted_at',
        'paper_checked_by', 'paper_checked_at', // sem QR: "Conferi os totais com o papel"
        'prices_include_vat', 'handwritten_notes', // talões com IVA incluído e notas escritas à mão
        // Descontos, quadro do IVA lido, linhas informativas, leitura original, "Reler" e quem fechou.
        'vat_table', 'ignored_lines', 'read_snapshot', 'reread_keep', 'closed_by', 'closed_at',
        // F2c
        'deleted_by', 'file_purged_at', 'file_sha256',
        // E-mail 7: de onde veio (carregamento manual ou anexo da caixa de correio, com a caixa e o Message-ID)
        'origin', 'origin_mailbox_id', 'origin_mailbox_email', 'origin_message_id',
    ];

    protected $casts = [
        'issue_date'        => 'date',
        'confidence'        => 'integer',
        'synced_to_pingwin' => 'boolean',
        'qr_ok'             => 'boolean',
        'qr_data'           => 'array',
        'attempts_log'      => 'array',
        'check_diff'        => 'array',
        'cost_usd'          => 'float',
        'link_diff_cents'   => 'integer',
        'link_checked_at'   => 'datetime',
        'link_candidates'   => 'array',
        'link_rejected'     => 'array',
        'link_search_pending' => 'boolean',
        'guide_refs'        => 'array',
        'check_accepted_at' => 'datetime',
        'paper_checked_at'  => 'datetime',
        'prices_include_vat' => 'boolean',
        'vat_table'         => 'array',
        'ignored_lines'     => 'array',
        'read_snapshot'     => 'array',
        'reread_keep'       => 'array',
        'closed_at'         => 'datetime',
        'file_purged_at'    => 'datetime',
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

    /** F3: documentos do PingWin ligados (N na fatura de guias). */
    public function pingwinLinks(): HasMany
    {
        return $this->hasMany(OcrInvoicePingwinLink::class, 'ocr_invoice_id');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(OcrInvoiceDiscount::class)->orderBy('position');
    }

    public function summary(): HasOne
    {
        return $this->hasOne(OcrInvoiceSummary::class);
    }
}
