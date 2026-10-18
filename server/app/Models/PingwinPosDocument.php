<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Auditoria v2-C · C3: um documento de caixa do PingWin (a linha da lista "Documentos"). */
class PingwinPosDocument extends Model
{
    public const VOID = '1002';
    public const TABLE_CHECK = '1031';
    public const SALES = ['1018', '1020'];
    public const CREDIT_NOTES = ['1412'];
    public const OFFERS = ['1009', '1404', '1300'];

    protected $guarded = ['id'];

    protected $casts = ['raw' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
}
