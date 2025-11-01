<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SocialLink extends Model
{
    protected $fillable = [
        'type',
        'value',
    ];

    /**
     * Entidade (User, Company, etc) que possui esta localização.
     */
    public function sociable(): MorphTo
    {
        return $this->morphTo();
    }
}
