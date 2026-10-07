<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Abertura real do link de uma cobrança (sem IP: identificador do browser em hash). */
class ExpenseChargeOpen extends Model
{
    public $timestamps = false;

    protected $fillable = ['expense_charge_id', 'visitor_hash', 'device', 'opened_at', 'last_seen_at'];

    protected $casts = ['opened_at' => 'datetime', 'last_seen_at' => 'datetime'];
}
