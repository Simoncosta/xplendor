<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * XPLENDOR — Uma contagem de stock (Fatia 7b): em_contagem → em_revisao → a_enviar → enviada (ou
 * falhou); anulada quando se anula no PingWin (ou se desiste antes de enviar).
 */
class StockCount extends Model
{
    public const SCOPES = ['completa', 'artigos_a', 'zona'];
    public const MOMENTS = ['antes_abertura', 'depois_fecho'];

    protected $fillable = [
        'company_id', 'warehouse_id', 'scope', 'zone_id', 'moment', 'inventory_date', 'blind', 'status', 'created_by', 'approved_by',
        'approved_at', 'pingwin_doc_id', 'pingwin_doc_number', 'error_message', 'sent_at', 'confirmed_at', 'voided_at',
    ];

    protected $casts = [
        'blind' => 'boolean', 'approved_at' => 'datetime', 'sent_at' => 'datetime', 'confirmed_at' => 'datetime', 'voided_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'count_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** A data no formato do PingWin guarda-se como AAAA-MM-DD (texto: igual em MySQL e sqlite). */
    public function dateString(): string
    {
        return substr((string) $this->getRawOriginal('inventory_date') ?: (string) $this->inventory_date, 0, 10);
    }
}
