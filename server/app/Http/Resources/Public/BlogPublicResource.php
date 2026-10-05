<?php

namespace App\Http\Resources\Public;

use App\Support\BlogHtml;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Artigo do blog para os sites dos clientes (allow-list). Nunca expõe company_id,
 * user_id nem o estado interno. O conteúdo é limpo à saída (lista de etiquetas
 * permitidas) e os campos de SEO/Open Graph vazios caem para o título e o resumo.
 */
class BlogPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $content = BlogHtml::sanitize($this->content);
        $description = $this->meta_description
            ?: ($this->excerpt ? Str::limit(BlogHtml::plainText($this->excerpt), 160) : Str::limit(BlogHtml::plainText($content), 160));
        $metaTitle = $this->meta_title ?: $this->title;

        return [
            'id'                 => $this->id,
            'title'              => $this->title,
            'subtitle'           => $this->subtitle,
            'slug'               => $this->slug,
            'banner'             => $this->banner,
            'excerpt'            => $this->excerpt,
            'content'            => $content,
            'tags'               => $this->tags,
            'category'           => $this->category,
            'published_at'       => optional($this->published_at)->toIso8601String(),
            'first_published_at' => optional($this->first_published_at ?? $this->published_at)->toIso8601String(),
            'read_time'          => $this->read_time,
            'meta_title'         => $metaTitle,
            'meta_description'   => $description,
            'focus_keyword'      => $this->focus_keyword,
            'og_title'           => $this->og_title ?: $metaTitle,
            'og_description'     => $this->og_description ?: $description,
            'og_image'           => $this->og_image ?: $this->banner,
            'created_at'         => optional($this->created_at)->toIso8601String(),
            'updated_at'         => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
