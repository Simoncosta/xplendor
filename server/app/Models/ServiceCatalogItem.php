<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Serviço da tabela padrão dos orçamentos (preço SEM IVA). Gerido pela equipa XPLENDOR. */
class ServiceCatalogItem extends Model
{
    protected $fillable = ['name', 'description', 'unit_price', 'unit', 'billing_type', 'active', 'sort'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'active'     => 'boolean',
        'sort'       => 'integer',
    ];
}
