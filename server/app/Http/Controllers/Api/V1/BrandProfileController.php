<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CompanyBrandProfile;
use App\Services\CollaboratorService;
use Illuminate\Http\Request;

/**
 * Perfil de marca simples da empresa (tom de voz, público, palavras a usar e a evitar,
 * temas a evitar). Ler: qualquer utilizador da empresa. Alterar: o administrador ou a
 * equipa XPLENDOR (as mesmas regras do conteúdo dos colaboradores).
 */
class BrandProfileController extends Controller
{
    public function show(int $companyId)
    {
        return ApiResponse::success($this->present(CompanyBrandProfile::where('company_id', $companyId)->first()), 'Perfil carregado.');
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
        ]);

        foreach (CompanyBrandProfile::LIST_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = array_values(array_unique(array_filter(array_map(fn ($v) => trim(strip_tags((string) $v)), (array) $data[$field]))));
            }
        }
        foreach (['tone_of_voice', 'audience'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = trim(strip_tags((string) $data[$field])) ?: null;
            }
        }

        $profile = CompanyBrandProfile::updateOrCreate(
            ['company_id' => $companyId],
            $data + ['updated_by_user_id' => $request->user()->id],
        );

        return ApiResponse::success($this->present($profile), 'Perfil da marca guardado.');
    }

    private function present(?CompanyBrandProfile $p): array
    {
        return [
            'tone_of_voice'   => $p?->tone_of_voice,
            'audience'        => $p?->audience,
            'words_to_use'    => $p?->words_to_use ?? [],
            'words_to_avoid'  => $p?->words_to_avoid ?? [],
            'topics_to_avoid' => $p?->topics_to_avoid ?? [],
            'language'        => $p?->language ?? 'pt-PT',
            'updated_at'      => optional($p?->updated_at)->toIso8601String(),
        ];
    }
}
