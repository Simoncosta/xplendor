<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** XPLENDOR — Um artigo que a Contagem (1106) do PingWin aceita (Fatia 7b). */
class PingwinCountableProduct extends Model
{
    protected $fillable = ['company_id', 'product_id', 'code', 'description', 'family', 'refreshed_at'];

    protected $casts = ['refreshed_at' => 'datetime'];
}
