<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\ConsultantSite;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Development;
use App\Models\Property;
use App\Services\ConsultantSite\ListingImageService;
use App\Services\ConsultantSite\PublishRules;
use App\Support\ConsultantSite\ChaveNovaUrl;
use App\Support\ConsultantSite\YoutubeId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Os empreendimentos em construção (área imoveis, módulo imoveis; publicar só tem efeito no site do consultor).
 * As frações são imóveis da carteira ligados ao empreendimento; o "preço desde" calcula-se das
 * frações publicadas e disponíveis. A localização é exata.
 */
class DevelopmentController extends Controller
{
    use HandlesListingImages;

    public function index(int $companyId)
    {
        $rows = Development::where('company_id', $companyId)->with(['parish.municipality', 'images'])->withCount('units')
            ->orderByDesc('id')->get()->map(fn (Development $d) => $this->present($d, false))->all();

        return ApiResponse::success(['developments' => $rows, 'options' => $this->options()], 'Empreendimentos.');
    }

    public function show(int $companyId, int $developmentId)
    {
        return ApiResponse::success($this->present($this->find($companyId, $developmentId), true), 'Empreendimento.');
    }

    public function store(Request $request, int $companyId)
    {
        $data = $this->validated($request, $companyId);
        $d = new Development($data + ['company_id' => $companyId]);
        $d->slug = $data['slug'] ?? null ?: $this->uniqueSlug($companyId, $data['name']);
        $d->save();

        return ApiResponse::success($this->present($d->fresh(['parish.municipality']), true), 'Empreendimento criado.', 201);
    }

    public function update(Request $request, int $companyId, int $developmentId)
    {
        $d = $this->find($companyId, $developmentId);
        $data = $this->validated($request, $companyId, $d->id);
        $data['slug'] = ($data['slug'] ?? null) ?: ($d->slug ?: $this->uniqueSlug($companyId, $data['name'], $d->id));
        $d->update($data);
        if ($d->site_status === 'publicado' && PublishRules::development($d) !== []) {
            $d->forceFill(['site_status' => 'rascunho'])->save();
        }

        return ApiResponse::success($this->present($d->fresh(['parish.municipality']), true), 'Empreendimento guardado.');
    }

    public function destroy(int $companyId, int $developmentId)
    {
        $d = $this->find($companyId, $developmentId);
        DB::transaction(function () use ($d) {
            Property::where('development_id', $d->id)->update(['development_id' => null]); // as frações ficam na carteira
            app(ListingImageService::class)->deleteAll($d);
            $d->delete();
        });

        return ApiResponse::success(null, 'Empreendimento apagado.');
    }

    public function publish(int $companyId, int $developmentId)
    {
        $d = $this->find($companyId, $developmentId);
        $missing = PublishRules::development($d);
        if ($missing !== []) {
            return ApiResponse::error('Ainda não pode publicar este empreendimento.', 422, ['publish' => $missing]);
        }
        $d->forceFill(['site_status' => 'publicado', 'published_at' => $d->published_at ?? now()])->save();

        return ApiResponse::success($this->present($d->fresh(['parish.municipality']), true), 'Empreendimento publicado no site.');
    }

    public function unpublish(int $companyId, int $developmentId)
    {
        $d = $this->find($companyId, $developmentId);
        $d->forceFill(['site_status' => 'rascunho'])->save();

        return ApiResponse::success($this->present($d->fresh(['parish.municipality']), true), 'Empreendimento retirado do site.');
    }

    public function storeImages(Request $request, int $companyId, int $developmentId)
    {
        return $this->storeImagesFor($request, $this->find($companyId, $developmentId));
    }

    public function orderImages(Request $request, int $companyId, int $developmentId)
    {
        return $this->orderImagesFor($request, $this->find($companyId, $developmentId));
    }

    public function updateImage(Request $request, int $companyId, int $developmentId, int $imageId)
    {
        return $this->updateImageFor($request, $this->find($companyId, $developmentId), $imageId);
    }

    public function destroyImage(int $companyId, int $developmentId, int $imageId)
    {
        $d = $this->find($companyId, $developmentId);
        $response = $this->destroyImageFor($d, $imageId);
        if ($d->site_status === 'publicado' && PublishRules::development($d) !== []) {
            $d->forceFill(['site_status' => 'rascunho'])->save();
        }

        return $response;
    }

    private function validated(Request $request, int $companyId, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('developments', 'slug')->where('company_id', $companyId)->ignore($ignoreId)],
            'short_description' => ['nullable', 'string', 'max:280'],
            'description' => ['nullable', 'string', 'max:8000'],
            'parish_id' => ['nullable', 'integer', 'exists:parishes,id'],
            'lat' => ['nullable', 'numeric', 'between:30,43', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-32,-6', 'required_with:lat'],
            'construction_phase' => ['required', Rule::in(array_keys(Development::PHASES))],
            'expected_completion' => ['nullable', 'date_format:Y-m'],
            'payment_plan' => ['nullable', 'string', 'max:4000'],
            'youtube' => ['nullable', 'string', 'max:255'],
            'chave_nova_url' => ['nullable', 'string', 'max:255'],
            'chave_nova_reference' => ['nullable', 'string', 'max:40'],
            'energy_class_expected' => ['nullable', Rule::in(Property::ENERGY_CLASSES)],
            'featured' => ['boolean'],
        ], [
            'slug.regex' => 'O endereço só leva letras minúsculas, números e hífenes.',
            'slug.unique' => 'Já existe um empreendimento com este endereço.',
            'expected_completion.date_format' => 'Indique o mês e o ano previstos.',
        ]);
        $raw = trim((string) ($data['youtube'] ?? ''));
        $data['youtube_id'] = $raw === '' ? null : (YoutubeId::parse($raw) ?? throw ValidationException::withMessages(['youtube' => 'Cole o link de um vídeo ou de um Short do YouTube.']));
        unset($data['youtube']);
        if (! ChaveNovaUrl::isAllowed($data['chave_nova_url'] ?? null)) {
            throw ValidationException::withMessages(['chave_nova_url' => 'A ligação tem de ser uma página https do site da rede (' . implode(' ou ', (array) config('consultor_site.chave_nova_domains')) . ').']);
        }
        $data['expected_completion'] = ! empty($data['expected_completion']) ? $data['expected_completion'] . '-01' : null;

        return $data;
    }

    private function present(Development $d, bool $detail): array
    {
        $out = [
            'id' => $d->id, 'name' => $d->name, 'slug' => $d->slug, 'short_description' => $d->short_description,
            'parish_id' => $d->parish_id, 'parish' => $d->parish?->name, 'municipality_id' => $d->parish?->municipality_id, 'municipality' => $d->parish?->municipality?->name,
            'district_id' => $d->parish?->municipality?->district_id,
            'lat' => $d->lat, 'lng' => $d->lng, 'construction_phase' => $d->construction_phase, 'construction_phase_label' => Development::PHASES[$d->construction_phase] ?? $d->construction_phase,
            'expected_completion' => $d->expected_completion?->format('Y-m'), 'energy_class_expected' => $d->energy_class_expected,
            'site_status' => $d->site_status, 'published_at' => $d->published_at?->toIso8601String(), 'featured' => (bool) $d->featured,
            'units_count' => $d->units_count ?? $d->units()->count(),
            'cover_url' => ($d->relationLoaded('images') ? $d->images->first() : $d->images()->first())?->path_480,
        ];
        if ($detail) {
            $out += [
                'description' => $d->description, 'payment_plan' => $d->payment_plan, 'youtube_id' => $d->youtube_id,
                'chave_nova_url' => $d->chave_nova_url, 'chave_nova_reference' => $d->chave_nova_reference,
                'images' => $this->imagesOf($d), 'publish_missing' => PublishRules::development($d),
                'publish_checklist' => PublishRules::developmentChecklist($d),
                'units' => $d->units()->orderBy('reference')->get(['id', 'reference', 'title', 'typology', 'price', 'price_on_request', 'status', 'site_status'])
                    ->map(fn (Property $u) => ['id' => $u->id, 'reference' => $u->reference, 'title' => $u->title, 'typology' => $u->typology, 'price' => $u->price,
                        'price_on_request' => (bool) $u->price_on_request, 'status' => $u->status, 'site_status' => $u->site_status])->all(),
            ];
        }

        return $out;
    }

    private function options(): array
    {
        return ['phases' => Development::PHASES, 'energy_classes' => Property::ENERGY_CLASSES];
    }

    private function find(int $companyId, int $developmentId): Development
    {
        $d = Development::with('parish.municipality')->where('company_id', $companyId)->find($developmentId);
        if (! $d) {
            abort(404, 'Empreendimento não encontrado.');
        }

        return $d;
    }

    private function uniqueSlug(int $companyId, string $source, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($source), 150, '') ?: 'empreendimento';
        $slug = $base;
        for ($i = 2; Development::withTrashed()->where('company_id', $companyId)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
