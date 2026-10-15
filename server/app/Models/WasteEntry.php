<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** XPLENDOR — Um registo de desperdício (Fatia 7b). Um prato com ficha explode nos componentes ao enviar. */
class WasteEntry extends Model
{
    public const REASONS = [
        'validade' => 'Fora de validade',
        'estragado' => 'Estragado ou mal conservado',
        'preparacao' => 'Erro de preparação',
        'devolvido' => 'Devolvido pelo cliente',
        'partido' => 'Partido ou entornado',
        'sobra' => 'Sobra de produção',
        'outro' => 'Outro',
    ];

    protected $fillable = [
        'company_id', 'warehouse_id', 'product_id', 'code', 'description', 'source_product_id', 'qty', 'unit', 'factor', 'qty_base', 'reason',
        'note', 'value_estimate', 'registered_by', 'registered_at', 'batch_id',
    ];

    protected $casts = ['qty' => 'float', 'factor' => 'float', 'qty_base' => 'float', 'value_estimate' => 'float', 'registered_at' => 'datetime'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(WasteBatch::class, 'batch_id');
    }

    public function registrant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
