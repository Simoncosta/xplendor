<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Fotografia de um público personalizado Meta (só metadados; nenhum dado pessoal). */
class MetaCustomAudience extends Model
{
    /** Subtipo da Meta para LISTAS DE CLIENTES (as únicas com time_content_updated). */
    public const SUBTYPE_CUSTOMER_LIST = 'CUSTOM';

    protected $fillable = [
        'company_id', 'account_id', 'audience_id', 'name', 'subtype',
        'approximate_count_lower_bound', 'approximate_count_upper_bound',
        'time_content_updated', 'delivery_status_code', 'delivery_status_description', 'fetched_at',
    ];

    protected $casts = [
        'approximate_count_lower_bound' => 'integer',
        'approximate_count_upper_bound' => 'integer',
        'time_content_updated' => 'datetime',
        'delivery_status_code' => 'integer',
        'fetched_at' => 'datetime',
    ];
}
