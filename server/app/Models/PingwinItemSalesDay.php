<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — Estado de cada loja × dia lido do "Vendas por artigo": a soma dos artigos
 * conferida com o líquido diário do Resumo de Vendas.
 */
class PingwinItemSalesDay extends Model
{
    /** A soma bate com o líquido diário (tolerância de 1%). */
    public const STATUS_OK = 'ok';
    /** A soma não bate com o líquido diário: volta a ler-se. */
    public const STATUS_MISMATCH = 'mismatch';
    /** Sem líquido diário para comparar (Resumo de Vendas ainda não sincronizado). */
    public const STATUS_UNVERIFIED = 'unverified';
    /** Dia sem vendas, confirmado pelo líquido diário a zero. */
    public const STATUS_EMPTY = 'empty';
    /** Relatório sem linhas mas o dia tem (ou pode ter) vendas: nada se apagou. */
    public const STATUS_EMPTY_PROTECTED = 'empty_protected';

    protected $fillable = [
        'company_id', 'location_id', 'business_date', 'status', 'rows_count',
        'items_net_cents', 'daily_net_cents', 'synced_at',
    ];

    protected $casts = [
        'rows_count'      => 'integer',
        'items_net_cents' => 'integer',
        'daily_net_cents' => 'integer',
        'synced_at'       => 'datetime',
    ];
}
