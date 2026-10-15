<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** XPLENDOR — Um documento de Desperdício enviado ao PingWin (Fatia 7b): as linhas pendentes de um armazém. */
class WasteBatch extends Model
{
    protected $fillable = [
        'company_id', 'warehouse_id', 'date', 'status', 'approved_by', 'approved_at', 'pingwin_doc_id', 'pingwin_doc_number', 'lines',
        'pingwin_total', 'error_message',
    ];

    protected $casts = ['approved_at' => 'datetime', 'lines' => 'array', 'pingwin_total' => 'float'];

    public function entries(): HasMany
    {
        return $this->hasMany(WasteEntry::class, 'batch_id');
    }
}
