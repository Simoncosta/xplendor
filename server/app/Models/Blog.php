<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Blog extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'subtitle',
        'slug',
        'banner',
        'excerpt',
        'content',
        'tags',
        'category',
        'status',
        'published_at',
        'user_id',
        'company_id',
    ];
}
