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
        'read_time',
        'meta_title',
        'meta_description',
        'og_title',
        'og_description',
        'og_image',
        'user_id',
        'company_id',
    ];

    protected $casts = [
        'tags' => 'array',
        'published_at' => 'datetime',
    ];
}
