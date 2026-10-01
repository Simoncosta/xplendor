<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Condição de pagamento do PingWin (paycond), só leitura (Fatia 1).
 * UNIQUE (company_id, pingwin_id) → UPSERT idempotente no sync.
 *
 * · discount = desconto financeiro em PERCENTAGEM (ex.: "2.50"), NÃO cêntimos.
 * · days     = dias de vencimento (int).
 * · tbdocs   = lista de documentos vinculados (filho tbdocs do PingWin). Guardada
 *   em JSON (não tabela filha) porque nesta fatia é SÓ para exibir: não há queries
 *   nem joins cruzados com docconfig ainda (o cross-docconfig é fatia posterior);
 *   o JSON mantém o filho fiel e segue o padrão `raw` dos restantes espelhos.
 * · raw      = detalhe completo do servidor (maindataset+tbdocs+additionalfields).
 */
class PingwinPaymentCondition extends Model
{
    protected $fillable = [
        'company_id', 'pingwin_id', 'code', 'description',
        'discount', 'days', 'is_active', 'tbdocs', 'raw', 'synced_at',
    ];

    protected $casts = [
        'discount'  => 'decimal:2',
        'days'      => 'integer',
        'is_active' => 'boolean',
        'tbdocs'    => 'array',
        'raw'       => 'array',
        'synced_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
