<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

class CarAiAnalysis extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'car_ai_analyses';

    protected $fillable = [
        'input_data',
        'analysis',
        'status',
        'feedback',
        'car_id',
        'company_id',
    ];
}
