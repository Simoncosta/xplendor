<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'logo',
        'banner_main',
        'title_about',
        'description_about',
        'address',
        'number',
        'city',
        'state',
        'zip_code',
        'show_address',
        'plan_id',
        'country_id',
    ];

    public function socialLinks(): MorphMany
    {
        return $this->morphMany(SocialLink::class, 'sociable');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(CompanyView::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(CompanyOperation::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
