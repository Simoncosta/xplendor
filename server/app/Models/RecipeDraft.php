<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** XPLENDOR — Fatia 4: o rascunho de uma ficha técnica (de uma pessoa), até "Gravar no PingWin". */
class RecipeDraft extends Model
{
    protected $fillable = ['company_id', 'product_pingwin_id', 'user_id', 'payload', 'base_signature'];

    protected $casts = ['payload' => 'array'];
}
