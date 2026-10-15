<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** XPLENDOR — Um armazém do PingWin (o nome, para o ecrã das fichas técnicas). */
class PingwinWarehouse extends Model
{
    protected $fillable = ['company_id', 'pingwin_id', 'description'];
}
