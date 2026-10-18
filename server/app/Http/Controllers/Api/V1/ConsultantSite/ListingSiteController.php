<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\ConsultantSite;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Development;
use App\Models\Property;
use App\Models\SiteDailyCount;
use App\Services\ConsultantSite\PublishRules;
use App\Support\ConsultantSite\ChaveNovaUrl;
use App\Support\ConsultantSite\YoutubeId;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Site do consultor: o site de cada imóvel da carteira (área imoveis, módulo site_consultor):
 * os campos do site, as fotografias, publicar e despublicar, e o resumo da lista (publicação,
 * capa e visualizações dos últimos 30 dias). Os campos da Bússola continuam no PropertyController.
 * Nenhum dado do proprietário. Sem classe energética não se publica.
 */
class ListingSiteController extends Controller
{
    use HandlesListingImages;

    /** Para a lista "Imóveis": a publicação, a capa e as visualizações dos últimos 30 dias. */
    public function summary(int $companyId)
    {
        $since = CarbonImmutable::now('Europe/Lisbon')->subDays(29)->toDateString();
        $views = fn (string $type) => SiteDailyCount::where('company_id', $companyId)->where('subject_type', $type)->where('event', 'view')
            ->where('day', '>=', $since)->groupBy('subject_id')->selectRaw('subject_id, SUM(count) as total')->pluck('total', 'subject_id');
        $pViews = $views('property');
        $dViews = $views('development');
        $properties = Property::where('company_id', $companyId)->with('images')->get(['id', 'site_status', 'slug', 'featured', 'show_as_sold', 'company_id'])
            ->mapWithKeys(fn (Property $p) => [$p->id => ['site_status' => $p->site_status, 'slug' => $p->slug, 'featured' => $p->featured,
                'cover_url' => $p->images->first()?->path_480, 'images' => $p->images->count(), 'views_30' => (int) ($pViews[$p->id] ?? 0)]]);
        $developments = Development::where('company_id', $companyId)->get(['id'])->mapWithKeys(fn (Development $d) => [$d->id => ['views_30' => (int) ($dViews[$d->id] ?? 0)]]);

        return ApiResponse::success(['properties' => (object) $properties->all(), 'developments' => (object) $developments->all()], 'Resumo do site.');
    }

    public function showProperty(int $companyId, int $propertyId)
    {
        return ApiResponse::success($this->present($this->find($companyId, $propertyId)), 'Imóvel no site.');
    }

    public function updateProperty(Request $request, int $companyId, int $propertyId)
    {
        $p = $this->find($companyId, $propertyId);
        if ($request->has('gross_area_m2')) {
            $request->merge(['gross_area_m2' => Property::parseArea($request->input('gross_area_m2'))]); // "110,5" ou "110.5"
        }
        $data = $request->validate([
            'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('properties', 'slug')->where('company_id', $companyId)->ignore($p->id)],
            'development_id' => ['nullable', 'integer', Rule::exists('developments', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'gross_area_m2' => ['nullable', 'numeric', 'min:1', 'max:99999.99', 'decimal:0,2'],
            'construction_state' => ['nullable', Rule::in(array_keys(Property::CONSTRUCTION_STATES))],
            'approx_lat' => ['nullable', 'numeric', 'between:30,43', 'required_with:approx_lng'],
            'approx_lng' => ['nullable', 'numeric', 'between:-32,-6', 'required_with:approx_lat'],
            'approx_radius_m' => ['nullable', 'integer', 'min:300', 'max:5000'],
            'price_on_request' => ['boolean'],
            'energy_precertificate' => ['boolean'],
            'short_description' => ['nullable', 'string', 'max:280'],
            'description' => ['nullable', 'string', 'max:8000'],
            'youtube' => ['nullable', 'string', 'max:255'],
            'chave_nova_url' => ['nullable', 'string', 'max:255'],
            'chave_nova_reference' => ['nullable', 'string', 'max:40'],
            'show_as_sold' => ['boolean'],
            'featured' => ['boolean'],
        ], [
            'slug.regex' => 'O endereço só leva letras minúsculas, números e hífenes.',
            'gross_area_m2.numeric' => 'A área é um número (por exemplo, 110,5).',
            'gross_area_m2.decimal' => 'A área leva no máximo duas casas decimais.',
            'slug.unique' => 'Já existe um imóvel com este endereço.',
            'approx_radius_m.min' => 'O raio mínimo é de 300 m: a localização de um usado é sempre aproximada.',
        ]);
        $data['youtube_id'] = $this->youtube($data);
        unset($data['youtube']);
        $this->checkChaveNova($data['chave_nova_url'] ?? null);
        if (empty($data['slug'])) {
            $data['slug'] = $p->slug ?: $this->uniqueSlug($companyId, "{$p->title} {$p->reference}", $p->id);
        }
        $p->update($data);
        // Publicado e deixou de cumprir as regras (por exemplo, fechado sem autorização): sai do site.
        if ($p->site_status === 'publicado' && PublishRules::property($p) !== []) {
            $p->forceFill(['site_status' => 'rascunho'])->save();
        }

        return ApiResponse::success($this->present($p->fresh()), 'Imóvel guardado.');
    }

    public function publishProperty(int $companyId, int $propertyId)
    {
        $p = $this->find($companyId, $propertyId);
        if (! $p->slug) {
            $p->forceFill(['slug' => $this->uniqueSlug($companyId, "{$p->title} {$p->reference}", $p->id)])->save();
        }
        $missing = PublishRules::property($p);
        if ($missing !== []) {
            return ApiResponse::error('Ainda não pode publicar este imóvel.', 422, ['publish' => $missing]);
        }
        $p->forceFill(['site_status' => 'publicado', 'published_at' => $p->published_at ?? now()])->save();

        return ApiResponse::success($this->present($p->fresh()), 'Imóvel publicado no site.');
    }

    public function unpublishProperty(int $companyId, int $propertyId)
    {
        $p = $this->find($companyId, $propertyId);
        $p->forceFill(['site_status' => 'rascunho'])->save();

        return ApiResponse::success($this->present($p->fresh()), 'Imóvel retirado do site.');
    }

    public function storePropertyImages(Request $request, int $companyId, int $propertyId)
    {
        return $this->storeImagesFor($request, $this->find($companyId, $propertyId));
    }

    public function orderPropertyImages(Request $request, int $companyId, int $propertyId)
    {
        return $this->orderImagesFor($request, $this->find($companyId, $propertyId));
    }

    public function updatePropertyImage(Request $request, int $companyId, int $propertyId, int $imageId)
    {
        return $this->updateImageFor($request, $this->find($companyId, $propertyId), $imageId);
    }

    public function destroyPropertyImage(int $companyId, int $propertyId, int $imageId)
    {
        $p = $this->find($companyId, $propertyId);
        $response = $this->destroyImageFor($p, $imageId);
        if ($p->site_status === 'publicado' && PublishRules::property($p) !== []) {
            $p->forceFill(['site_status' => 'rascunho'])->save(); // sem fotografias não fica publicado
        }

        return $response;
    }

    private function present(Property $p): array
    {
        return [
            'id' => $p->id, 'reference' => $p->reference, 'title' => $p->title, 'slug' => $p->slug, 'development_id' => $p->development_id,
            'bedrooms' => $p->bedrooms, 'bathrooms' => $p->bathrooms, 'gross_area_m2' => $p->gross_area_m2, 'construction_state' => $p->construction_state,
            'approx_lat' => $p->approx_lat, 'approx_lng' => $p->approx_lng, 'approx_radius_m' => $p->approx_radius_m,
            'price_on_request' => (bool) $p->price_on_request, 'energy_precertificate' => (bool) $p->energy_precertificate,
            'short_description' => $p->short_description, 'description' => $p->description, 'youtube_id' => $p->youtube_id,
            'chave_nova_url' => $p->chave_nova_url, 'chave_nova_reference' => $p->chave_nova_reference,
            'site_status' => $p->site_status, 'published_at' => $p->published_at?->toIso8601String(), 'show_as_sold' => (bool) $p->show_as_sold,
            'featured' => (bool) $p->featured, 'images' => $this->imagesOf($p), 'publish_missing' => PublishRules::property($p),
            'publish_checklist' => PublishRules::propertyChecklist($p),
            'options' => [
                'construction_states' => Property::CONSTRUCTION_STATES,
                'developments' => Development::where('company_id', $p->company_id)->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->all(),
            ],
        ];
    }

    private function find(int $companyId, int $propertyId): Property
    {
        $p = Property::where('company_id', $companyId)->find($propertyId);
        if (! $p) {
            abort(404, 'Imóvel não encontrado.');
        }

        return $p;
    }

    private function youtube(array $data): ?string
    {
        $raw = trim((string) ($data['youtube'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $id = YoutubeId::parse($raw);
        if ($id === null) {
            throw ValidationException::withMessages(['youtube' => 'Cole o link de um vídeo ou de um Short do YouTube.']);
        }

        return $id;
    }

    private function checkChaveNova(?string $url): void
    {
        if (! ChaveNovaUrl::isAllowed($url)) {
            throw ValidationException::withMessages(['chave_nova_url' => 'A ligação tem de ser uma página https do site da rede (' . implode(' ou ', (array) config('consultor_site.chave_nova_domains')) . ').']);
        }
    }

    private function uniqueSlug(int $companyId, string $source, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($source), 150, '') ?: 'imovel';
        $slug = $base;
        for ($i = 2; Property::withTrashed()->where('company_id', $companyId)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
