<?php

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Artigo do blog para os sites dos clientes (allow-list). Nunca expõe company_id,
 * user_id nem o estado interno.
 */
class BlogPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'subtitle'         => $this->subtitle,
            'slug'             => $this->slug,
            'banner'           => $this->banner,
            'excerpt'          => $this->excerpt,
            'content'          => $this->content,
            'tags'             => $this->tags,
            'category'         => $this->category,
            'published_at'     => optional($this->published_at)->toIso8601String(),
            'read_time'        => $this->read_time,
            'meta_title'       => $this->meta_title,
            'meta_description' => $this->meta_description,
            'og_title'         => $this->og_title,
            'og_description'   => $this->og_description,
            'og_image'         => $this->og_image,
            'created_at'       => optional($this->created_at)->toIso8601String(),
            'updated_at'       => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
