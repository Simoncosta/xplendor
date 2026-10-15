<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** XPLENDOR — Uma leitura do stock no PingWin (comando, agendamento ou um artigo), com tempos e falhas. */
class PingwinStockRun extends Model
{
    protected $fillable = [
        'company_id', 'origin', 'status', 'products_total', 'products_read', 'products_failed', 'rows', 'seconds', 'error',
        'started_at', 'finished_at',
    ];

    protected $casts = ['seconds' => 'float', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
}
