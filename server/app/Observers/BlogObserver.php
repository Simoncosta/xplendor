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
            $slug = $baseSlug . '-' . $count;
            $count++;
        }

        $blog->slug = $slug;

        // Cálculo de tempo estimado de leitura
        $wordCount = str_word_count(strip_tags($blog->content));
        $blog->read_time = max(1, ceil($wordCount / 200)); // 200 palavras por minuto

        // SEO Defaults
        if (empty($blog->meta_title)) {
            $blog->meta_title = $blog->title;
        }

        if (empty($blog->meta_description)) {
            $blog->meta_description = Str::limit(strip_tags($blog->excerpt ?? $blog->content), 160);
        }

        if (empty($blog->og_title)) {
            $blog->og_title = $blog->title;
        }

        if (empty($blog->og_description)) {
            $blog->og_description = Str::limit(strip_tags($blog->excerpt ?? $blog->content), 160);
        }
    }

    public function updating(Blog $blog)
    {
        if ($blog->isDirty('title')) {
            $baseSlug = Str::slug($blog->title);
            $slug = $baseSlug;
            $count = 1;

            while (Blog::where('slug', $slug)->where('id', '!=', $blog->id)->exists()) {
                $slug = $baseSlug . '-' . $count;
                $count++;
            }

            $blog->slug = $slug;
        }

        if ($blog->isDirty('content')) {
            $wordCount = str_word_count(strip_tags($blog->content));
            $blog->read_time = max(1, ceil($wordCount / 200));
        }
    }
}
