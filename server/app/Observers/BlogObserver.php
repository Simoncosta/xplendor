<?php

namespace App\Observers;

use Illuminate\Support\Str;
use App\Models\Blog;

class BlogObserver
{
    public function creating(Blog $blog)
    {
        $baseSlug = Str::slug($blog->title);
        $slug = $baseSlug;
        $count = 1;

        while (Blog::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $count++;
        }

        $blog->slug = $slug;
    }

    public function updating(Blog $blog)
    {
        if ($blog->isDirty('title')) {
            $blog->slug = Str::slug($blog->title);
        }
    }
}
