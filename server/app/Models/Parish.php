<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

class Parish extends Model implements AuditableContract
{
    use Auditable;

    protected $fillable = [
        'name',
        'website',
        'facebook',
        'municipality_id',
    ];

    /** Bússola Master: o concelho (para o código DICO e a referência do INE). */
    public function municipality(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }
}
