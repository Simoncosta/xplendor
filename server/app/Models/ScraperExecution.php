<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScraperExecution extends Model
{
    protected $fillable = [
        'company_id',
        'source',
        'filters',
        'status',
        'mode',
        'total_raw',
        'total_normalized',
        'total_sent',
        'total_failed',
        'logs_excerpt',
        'output',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'company_id'       => 'integer',
        'filters'          => 'array',
        'mode'             => 'string',
        'total_raw'        => 'integer',
        'total_normalized' => 'integer',
        'total_sent'       => 'integer',
        'total_failed'     => 'integer',
        'started_at'       => 'datetime',
        'finished_at'      => 'datetime',
    ];
}
