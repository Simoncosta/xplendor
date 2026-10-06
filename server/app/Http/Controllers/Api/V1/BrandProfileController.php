<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CompanyBrandProfile;
use App\Services\CollaboratorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Perfil de marca simples da empresa (tom de voz, público, palavras a usar e a evitar,
 * temas a evitar). Ler: qualquer utilizador da empresa. Alterar: o administrador ou a
 * equipa XPLENDOR (as mesmas regras do conteúdo dos colaboradores).
 */
class BrandProfileController extends Controller
{
    public function show(Request $request, int $companyId)
    {
        return ApiResponse::success(
            $this->present(CompanyBrandProfile::where('company_id', $companyId)->first())
                + ['can_edit' => CollaboratorService::canEditContent($request->user(), $companyId)],
            'Perfil carregado.'
        );
    }

    public function update(Request $request, int $companyId)
    {
        if (! CollaboratorService::canEditContent($request->user(), $companyId)) {
            return ApiResponse::error('Só o administrador da empresa pode alterar o perfil da marca.', 403);
        }

        $data = $request->validate([
            'tone_of_voice'     => ['nullable', 'string', 'max:2000'],
            'audience'          => ['nullable', 'string', 'max:2000'],
            'words_to_use'      => ['nullable', 'array', 'max:30'],
            'words_to_use.*'    => ['nullable', 'string', 'max:60'],
            'words_to_avoid'    => ['nullable', 'array', 'max:30'],
            'words_to_avoid.*'  => ['nullable', 'string', 'max:60'],
            'topics_to_avoid'   => ['nullable', 'array', 'max:30'],
            'topics_to_avoid.*' => ['nullable', 'string', 'max:120'],
            // Campos previstos para brand_profiles na F1.
            'pillars'               => ['nullable', 'array', 'max:8'],
            'pillars.*.name'        => ['required_with:pillars', 'string', 'max:60'],
            'pillars.*.description' => ['nullable', 'string', 'max:300'],
            'hashtags_default'      => ['nullable', 'array', 'max:30'],
            'hashtags_default.*'    => ['nullable', 'string', 'max:60'],
            'cta_default'           => ['nullable', 'string', 'max:300'],
            'emoji_policy'          => ['nullable', Rule::in(CompanyBrandProfile::EMOJI_POLICIES)],
            'notes'                 => ['nullable', 'string', 'max:2000'],
        ], [
            'pillars.*.name.required_with' => 'Cada pilar precisa de um nome.',
        ]);

        foreach (CompanyBrandProfile::LIST_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = array_values(array_unique(array_filter(array_map(fn ($v) => trim(strip_tags((string) $v)), (array) $data[$field]))));
            }
        }
        if (array_key_exists('hashtags_default', $data)) {
            // Uma hashtag é uma palavra: sem espaços, sempre com "#".
            $data['hashtags_default'] = array_values(array_unique(array_filter(array_map(
                fn ($h) => ($h = preg_replace('/[\s#]+/u', '', (string) $h)) === '' ? null : '#' . $h,
                $data['hashtags_default']
            ))));
        }
        if (array_key_exists('pillars', $data)) {
            $data['pillars'] = array_values(array_map(fn ($p) => [
                'name'        => trim(strip_tags((string) ($p['name'] ?? ''))),
                'description' => trim(strip_tags((string) ($p['description'] ?? ''))) ?: null,
            ], (array) ($data['pillars'] ?? [])));
        }
        foreach (['tone_of_voice', 'audience', 'cta_default', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = trim(strip_tags((string) $data[$field])) ?: null;
            }
        }

        $profile = CompanyBrandProfile::updateOrCreate(
            ['company_id' => $companyId],
            $data + ['updated_by_user_id' => $request->user()->id],
        );

        return ApiResponse::success(
            $this->present($profile) + ['can_edit' => true],
            'Perfil da marca guardado.'
        );
    }

    private function present(?CompanyBrandProfile $p): array
    {
        return [
            'tone_of_voice'   => $p?->tone_of_voice,
            'audience'        => $p?->audience,
            'words_to_use'    => $p?->words_to_use ?? [],
            'words_to_avoid'  => $p?->words_to_avoid ?? [],
            'topics_to_avoid' => $p?->topics_to_avoid ?? [],
            'pillars'          => $p?->pillars ?? [],
            'hashtags_default' => $p?->hashtags_default ?? [],
            'cta_default'      => $p?->cta_default,
            'emoji_policy'     => $p?->emoji_policy,
            'notes'            => $p?->notes,
            'is_empty'         => $p === null || $p->isEmpty(),
            // Mínimo para "Gerar ideias" (o servidor recusa sem ele).
            'ideas_ready'      => CompanyBrandProfile::missingForIdeas($p) === [],
            'ideas_blocked_reason' => CompanyBrandProfile::ideasBlockedReason($p),
            'language'        => $p?->language ?? 'pt-PT',
            'updated_at'      => optional($p?->updated_at)->toIso8601String(),
        ];
    }
}
