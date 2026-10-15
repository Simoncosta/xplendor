<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** XPLENDOR — Uma loja de uma versão da ficha técnica (ativa = deleted 0 no PingWin). */
class PingwinProductBomStore extends Model
{
    protected $fillable = ['company_id', 'bom_pingwin_id', 'store_pingwin_id', 'store_code', 'store_name', 'active'];

    protected $casts = ['active' => 'boolean'];
}
