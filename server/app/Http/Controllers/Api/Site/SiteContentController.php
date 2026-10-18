<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Site;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Site\SitePresenter;
use App\Models\Blog;
use App\Models\ConsultantSite;
use App\Models\Development;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * API do site do consultor (só leitura): o perfil, os imóveis e os empreendimentos publicados e
 * os artigos publicados do blog da empresa. O site e a empresa vêm do middleware site_server
 * (token do contentor + host). Só conteúdo publicado, em lista branca (SitePresenter).
 */
class SiteContentController extends Controller
{
    private const PER_PAGE_MAX = 48;

    public function profile(Request $request)
    {
        return ApiResponse::success(SitePresenter::profile($this->site($request)), 'Perfil.');
    }

    public function properties(Request $request)
    {
        $site = $this->site($request);
        $f = $request->validate([
            'kind' => ['nullable', Rule::in(array_keys(Property::KINDS))],
            'typology' => ['nullable', Rule::in(Property::TYPOLOGIES)],
            'listing' => ['nullable', Rule::in(array_keys(Property::LISTINGS))],
            'construction_state' => ['nullable', Rule::in(array_keys(Property::CONSTRUCTION_STATES))],
            'parish' => ['nullable', 'string', 'size:6'],      // DICOFRE
            'municipality' => ['nullable', 'string', 'size:4'], // DICO
            'price_min' => ['nullable', 'integer', 'min:0'],
            'price_max' => ['nullable', 'integer', 'min:0'],
            'featured' => ['nullable', 'boolean'],
            'availability' => ['nullable', Rule::in(['disponivel', 'reservado', 'vendido'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::PER_PAGE_MAX],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $preview = $this->preview($request);
        $facets = $this->facets($this->propertyQuery($site, $preview)->get());
        $q = $this->propertyQuery($site, $preview)
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($f['typology'] ?? null, fn ($q, $v) => $q->where('typology', $v))
            ->when($f['listing'] ?? null, fn ($q, $v) => $q->where('listing', $v))
            ->when($f['construction_state'] ?? null, fn ($q, $v) => $q->where('construction_state', $v))
            ->when($f['parish'] ?? null, fn ($q, $v) => $q->whereHas('parish', fn ($p) => $p->where('dicofre', $v)))
            ->when($f['municipality'] ?? null, fn ($q, $v) => $q->whereHas('parish.municipality', fn ($m) => $m->where('dico', $v)))
            ->when(isset($f['price_min']), fn ($q) => $q->where('price_on_request', false)->where('price', '>=', (int) $f['price_min']))
            ->when(isset($f['price_max']), fn ($q) => $q->where('price_on_request', false)->where('price', '<=', (int) $f['price_max']))
            ->when($request->boolean('featured'), fn ($q) => $q->where('featured', true))
            ->when($f['availability'] ?? null, fn ($q, $v) => $v === 'vendido' ? $q->where('status', 'fechado') : $q->where('status', $v))
            ->orderByDesc('featured')->orderByDesc('published_at')->orderByDesc('id');
        $page = $q->paginate((int) ($f['per_page'] ?? 24));

        return ApiResponse::success([
            'items' => collect($page->items())->map(fn (Property $p) => SitePresenter::propertyCard($p))->values()->all(),
            'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
            'facets' => $facets,
        ], 'Imóveis.');
    }

    public function property(Request $request, string $slug)
    {
        $preview = $this->preview($request);
        $p = $this->bySlug($this->propertyQuery($this->site($request), $preview), $slug, $preview)->first();
        if (! $p) {
            return ApiResponse::error('Imóvel não encontrado.', 404);
        }

        return ApiResponse::success(SitePresenter::propertyDetail($p), 'Imóvel.');
    }

    public function developments(Request $request)
    {
        $items = $this->developmentQuery($this->site($request), $this->preview($request))->orderByDesc('featured')->orderByDesc('published_at')->orderByDesc('id')->get()
            ->map(fn (Development $d) => SitePresenter::developmentCard($d))->values()->all();

        return ApiResponse::success(['items' => $items], 'Empreendimentos.');
    }

    public function development(Request $request, string $slug)
    {
        $d = $this->developmentQuery($this->site($request), $this->preview($request))->where('slug', $slug)->first();
        if (! $d) {
            return ApiResponse::error('Empreendimento não encontrado.', 404);
        }

        return ApiResponse::success(SitePresenter::developmentDetail($d), 'Empreendimento.');
    }

    public function blog(Request $request)
    {
        $site = $this->site($request);
        $f = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::PER_PAGE_MAX], 'page' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', 'max:120']]);
        $q = $this->blogQuery($site);
        if (! empty($f['category'])) {
            // As categorias são texto livre: o slug calcula-se e filtra-se pelas que lhe correspondem.
            $names = $this->blogQuery($site)->whereNotNull('category')->distinct()->pluck('category')
                ->filter(fn ($c) => Str::slug((string) $c) === $f['category'])->values()->all();
            $q->whereIn('category', $names === [] ? ['__nenhuma__'] : $names);
        }
        $page = $q->orderByDesc('published_at')->orderByDesc('id')->paginate((int) ($f['per_page'] ?? 12));

        return ApiResponse::success([
            'items' => collect($page->items())->map(fn (Blog $b) => SitePresenter::blogCard($b, $site))->values()->all(),
            'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ], 'Artigos.');
    }

    public function blogPost(Request $request, string $slug)
    {
        $site = $this->site($request);
        $b = $this->blogQuery($site)->where('slug', $slug)->first();
        if (! $b) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }

        return ApiResponse::success(SitePresenter::blog($b, $site), 'Artigo.');
    }

    /** Os filtros possíveis (só o que existe na carteira visível). @param \Illuminate\Support\Collection<int, Property> $all */
    private function facets($all): array
    {
        $prices = $all->filter(fn (Property $p) => ! $p->price_on_request && $p->price > 0)->pluck('price');
        $parishes = $all->filter(fn (Property $p) => $p->parish && $p->parish->dicofre)
            ->map(fn (Property $p) => ['dicofre' => $p->parish->dicofre, 'name' => $p->parish->name, 'municipality' => $p->parish->municipality?->name])
            ->unique('dicofre')->sortBy('name')->values()->all();

        return [
            'kinds' => collect(Property::KINDS)->only($all->pluck('kind')->unique()->all())->all(),
            'typologies' => array_values(array_intersect(Property::TYPOLOGIES, $all->pluck('typology')->filter()->unique()->all())),
            'construction_states' => collect(Property::CONSTRUCTION_STATES)->only($all->pluck('construction_state')->filter()->unique()->all())->all(),
            'parishes' => $parishes,
            'price_min' => $prices->isEmpty() ? null : (int) $prices->min(),
            'price_max' => $prices->isEmpty() ? null : (int) $prices->max(),
        ];
    }

    private function site(Request $request): ConsultantSite
    {
        return $request->attributes->get('consultant_site');
    }

    /** Fora da pré-visualização, só o publicado e visível; na pré-visualização, também os rascunhos. */
    private function propertyQuery(ConsultantSite $site, bool $preview = false): Builder
    {
        $q = Property::query()->where('company_id', $site->company_id)->with(['parish.municipality', 'images', 'development']);

        return $preview ? $q->visibleInPreview() : $q->visibleOnSite()->whereNotNull('slug');
    }

    private function developmentQuery(ConsultantSite $site, bool $preview = false): Builder
    {
        $units = fn ($q) => ($preview ? $q->visibleInPreview() : $q->visibleOnSite()->whereNotNull('slug'))->with(['parish.municipality', 'images'])->orderBy('price');

        return Development::query()->where('company_id', $site->company_id)->{$preview ? 'visibleInPreview' : 'visibleOnSite'}()
            ->with(['parish.municipality', 'images', 'units' => $units]);
    }

    private function preview(Request $request): bool
    {
        return (bool) $request->attributes->get('site_preview');
    }

    /** Um imóvel pelo endereço; na pré-visualização, também pelo endereço provisório de um rascunho. */
    private function bySlug(Builder $q, string $slug, bool $preview): Builder
    {
        return $preview && preg_match('/^rascunho-(\d+)$/', $slug, $m) ? $q->whereNull('slug')->whereKey((int) $m[1]) : $q->where('slug', $slug);
    }

    /** Os mesmos artigos que o blog público: só os publicados e já com data. */
    private function blogQuery(ConsultantSite $site): Builder
    {
        return Blog::query()->where('company_id', $site->company_id)->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }
}
