<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\BlogWorkflowService;
use App\Support\BlogHtml;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Artigo para o backoffice. O conteúdo volta a ser limpo à saída (defesa para registos
 * antigos ou escritos por outra via). Inclui as permissões do utilizador atual e os
 * "[VERIFICAR]" por resolver, para o editor mostrar o que bloqueia a aprovação.
 */
class BlogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id'                  => $this->id,
            'company_id'          => $this->company_id,
            'title'               => $this->title,
            'subtitle'            => $this->subtitle,
            'slug'                => $this->slug,
            'banner'              => $this->banner,
            'excerpt'             => $this->excerpt,
            'content'             => BlogHtml::sanitize($this->content),
            'tags'                => $this->tags ?? [],
            'category'            => $this->category,
            'status'              => $this->status,
            'published_at'        => optional($this->published_at)->toIso8601String(),
            'first_published_at'  => optional($this->first_published_at)->toIso8601String(),
            'read_time'           => $this->read_time,
            'meta_title'          => $this->meta_title,
            'meta_description'    => $this->meta_description,
            'focus_keyword'       => $this->focus_keyword,
            'seo_answer_first_ok' => (bool) $this->seo_answer_first_ok,
            'og_image'            => $this->og_image,
            'review_note'         => $this->review_note,
            'submitted_at'        => optional($this->submitted_at)->toIso8601String(),
            'approved_at'         => optional($this->approved_at)->toIso8601String(),
            'author_name'         => $this->author?->name,
            'submitted_by_name'   => $this->submitter?->name,
            'approved_by_name'    => $this->approver?->name,
            'site_url'            => $this->company?->website,
            'unresolved_markers'  => BlogWorkflowService::unresolvedMarkers($this->resource),
            'permissions'         => $user ? BlogWorkflowService::permissions($user, $this->resource) : null,
            'created_at'          => optional($this->created_at)->toIso8601String(),
            'updated_at'          => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
