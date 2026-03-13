<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

class CarAiAnalysis extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'car_ai_analyses';

    protected $fillable = [
        'input_data',
        'analysis_raw',
        'analysis',
        'score_conversao',
        'score_classificacao',
        'urgency_level',
        'price_alert',
        'status',
        'feedback',
        'car_id',
        'company_id',
    ];

    protected $casts = [
        'input_data'    => 'array',
        'analysis'      => 'array',
        'price_alert'   => 'boolean',
        'score_conversao' => 'integer',
    ];

    // Esconde o campo raw da API — só para fallback/debug interno
    protected $hidden = ['analysis_raw'];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
