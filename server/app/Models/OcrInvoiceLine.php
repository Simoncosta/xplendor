<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Linha de uma fatura OCR. Totais em CÊNTIMOS; preço unitário e quantidade com
 * 6 casas (F2b: nunca arredondar o preço). Ligação ao artigo do catálogo (F2b).
 */
class OcrInvoiceLine extends Model
{
    public const LINKED = 'ligada';
    public const SUGGESTED = 'sugerida';
    public const UNLINKED = 'por_ligar';

    /** Métodos manuais: nunca são refeitos pela ligação automática. */
    public const MANUAL_METHODS = ['manual', 'sugestao', 'criado'];

    protected $fillable = [
        'ocr_invoice_id', 'company_id', 'position', 'supplier_code', 'item', 'quantity', 'unit',
        'unit_price', 'discount_pct', 'line_total_cents', 'vat_rate',
        'article_id', 'link_state', 'link_method', 'link_confidence', 'link_suggestions', 'linked_by', 'linked_at',
        'article_write_id', 'supplier_code_status', 'supplier_code_error', 'supplier_code_write_id', 'vat_write_id',
        'launch_unit_id', // FB-1
        // Descontos de promoção ligados ao artigo: line_total_cents é o líquido (bruto − desconto).
        'promo_code', 'vat_code', 'gross_total_cents', 'discount_cents', 'discount_code', 'discount_label',
        // O IEC de uma coluna à parte, já somado ao valor da linha (só para o mostrar).
        'iec_cents',
    ];

    protected $casts = [
        'position'         => 'integer',
        'quantity'         => 'decimal:6',
        'unit_price'       => 'decimal:6',
        'discount_pct'     => 'float',
        'line_total_cents' => 'integer',
        'gross_total_cents' => 'integer',
        'discount_cents'   => 'integer',
        'iec_cents'        => 'integer',
        'vat_rate'         => 'integer',
        'article_id'       => 'integer',
        'link_confidence'  => 'float',
        'link_suggestions' => 'array',
        'linked_at'        => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(OcrInvoice::class, 'ocr_invoice_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(PingwinCatalogItem::class, 'article_id');
    }

    /** O valor da linha antes do desconto de promoção (o lido na fatura). */
    public function grossCents(): int
    {
        return (int) ($this->gross_total_cents ?? ((int) $this->line_total_cents + (int) $this->discount_cents));
    }

    public function isManual(): bool
    {
        return in_array($this->link_method, self::MANUAL_METHODS, true);
    }
}
