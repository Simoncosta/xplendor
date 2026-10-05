<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CreativeFormatRule;
use App\Models\EditorialPost;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Regras de formato (referência de mercado) usadas pelo "Sugerir criativo". Só o root
 * (grupo /admin com ensure_super_admin). A fonte acompanha sempre cada regra.
 */
class CreativeFormatRuleController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'rules'   => CreativeFormatRule::orderBy('channel')->orderBy('followers_min')->orderBy('rank')->get(),
            'formats' => collect(EditorialPost::MEDIA_FORMATS)->map(fn ($keys) => array_map(
                fn ($k) => ['value' => $k, 'label' => EditorialPost::MEDIA_FORMAT_LABELS[$k]], $keys
            )),
        ], 'Regras carregadas.');
    }

    public function store(Request $request)
    {
        $rule = CreativeFormatRule::create($this->validated($request) + ['updated_by_user_id' => $request->user()->id]);

        return ApiResponse::success($rule, 'Regra criada.', 201);
    }

    public function update(Request $request, int $ruleId)
    {
        $rule = CreativeFormatRule::findOrFail($ruleId);
        $rule->update($this->validated($request) + ['updated_by_user_id' => $request->user()->id]);

        return ApiResponse::success($rule->fresh(), 'Regra guardada.');
    }

    public function destroy(int $ruleId)
    {
        CreativeFormatRule::findOrFail($ruleId)->delete();

        return ApiResponse::success(null, 'Regra apagada.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'channel'         => ['required', Rule::in(array_keys(EditorialPost::MEDIA_FORMATS))],
            'followers_min'   => ['required', 'integer', 'min:0'],
            'followers_max'   => ['nullable', 'integer', 'gte:followers_min'],
            'format_key'      => ['required', 'string'],
            'engagement_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rank'            => ['required', 'integer', 'min:1', 'max:20'],
            'note'            => ['nullable', 'string', 'max:500'],
            'source_label'    => ['required', 'string', 'max:255'],
            'source_url'      => ['nullable', 'url', 'max:500'],
            'is_active'       => ['sometimes', 'boolean'],
        ], [
            'followers_max.gte'     => 'O máximo de seguidores tem de ser maior ou igual ao mínimo.',
            'source_label.required' => 'Indique a fonte da regra.',
        ]);

        if (! in_array($data['format_key'], EditorialPost::MEDIA_FORMATS[$data['channel']], true)) {
            throw ValidationException::withMessages(['format_key' => ['Escolha um formato válido para esta rede.']]);
        }

        return $data;
    }
}
