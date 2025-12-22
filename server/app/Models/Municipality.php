<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

class Municipality extends Model implements AuditableContract
{
    use Auditable;

    protected $fillable = [
        'name',
        'website',
        'district_id',
    ];
}
