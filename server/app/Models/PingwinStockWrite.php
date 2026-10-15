<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Uma escrita no PingWin da Fatia 7a, iniciada por uma pessoa e confirmada por releitura:
 * o mínimo, o máximo ou o "Não usa genéricos" de um artigo num armazém (limites), ou criar, editar
 * ou apagar um armazém.
 */
class PingwinStockWrite extends Model
{
    public const KINDS = ['limites', 'armazem_criar', 'armazem_editar', 'armazem_apagar', 'contagem', 'desperdicio', 'anular_documento', 'guia_saida', 'entrada_armazem', 'encomenda_teste', 'semente_teste'];

    public const FINAL = ['ok', 'mismatch', 'conflict', 'failed'];

    protected $fillable = [
        'company_id', 'user_id', 'kind', 'product_pingwin_id', 'warehouse_pingwin_id', 'payload', 'expected', 'status',
        'result', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'expected' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
