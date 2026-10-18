<?php

declare(strict_types=1);

namespace App\Http\Resources\Site;

use App\Http\Resources\Public\BlogPublicResource;
use App\Models\Blog;
use App\Models\ConsultantSite;
use App\Models\Development;
use App\Models\IneHousingPrice;
use App\Models\ListingImage;
use App\Models\Property;
use App\Support\ConsultantSite\PublicFileUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * API do site do consultor: o que sai para o site, em lista branca. Nunca: IDs internos, a
 * empresa, o preço de um imóvel "sob consulta", as contagens de interesse, as datas de carteira,
 * as notas, o token nem os contactos. A localização dos usados é só o centro do círculo,
 * arredondado (cerca de 100 m), com o raio; a dos empreendimentos é exata.
 */
final class SitePresenter
{
    public static function profile(ConsultantSite $s): array
    {
        $partner = (array) $s->credit_partner;
        $partnerOn = $s->credit_partner_enabled && trim((string) ($partner['legal_name'] ?? '')) !== '' && trim((string) ($partner['bp_registration'] ?? '')) !== '';
        $links = (array) $s->legal_links;

        $s->loadMissing('company');
        $controller = $s->dataController();
        unset($controller['is_company']);
        $whatsapp = $s->whatsappNumber();
        $parishes = $s->parishes()->with('municipality')->orderBy('name')->get();

        return [
            'public_name' => $s->publicName(),
            'primary_host' => $s->primaryHost(),
            'signature_url' => PublicFileUrl::absolute($s->signature_svg_path),
            'photo_url' => PublicFileUrl::absolute($s->photo_cutout_path),
            'about_photo_url' => PublicFileUrl::absolute($s->about_photo_path),
            'share_image_url' => PublicFileUrl::absolute($s->share_image_path),
            'hero' => [
                'video_mp4_url' => PublicFileUrl::absolute($s->hero_video_mp4_path),
                'video_webm_url' => PublicFileUrl::absolute($s->hero_video_webm_path),
                'poster_url' => PublicFileUrl::absolute($s->hero_poster_path),
                'poster_960_url' => PublicFileUrl::absolute($s->hero_poster_960_path),
                'promise' => $s->hero_promise,
            ],
            'bio_short' => $s->bio_short,
            'intro_video' => $s->intro_video_enabled && $s->intro_youtube_id ? ['youtube_id' => $s->intro_youtube_id] : null,
            'featured_videos' => array_map(fn (string $id) => ['youtube_id' => $id], $s->featuredVideoIds()),
            'zone_photos' => self::zonePhotos($s, $parishes),
            'brokerage' => ['legal_name' => $s->brokerage_legal_name, 'ami' => $s->brokerage_ami],
            'network' => ['label' => $s->network_label, 'text' => $s->network_text, 'logo_url' => PublicFileUrl::absolute($s->network_logo_path)],
            'legal_links' => [
                'complaints_book_url' => $links['complaints_book_url'] ?? null,
                'ral' => array_values(array_filter((array) ($links['ral'] ?? []), fn ($e) => is_array($e) && ! empty($e['name']) && ! empty($e['url']))),
                'legal_info_text' => $links['legal_info_text'] ?? null,
            ],
            'data_controller' => $controller,
            'privacy_policy_version' => $s->privacy_policy_version,
            'contact_retention_months' => max(1, (int) config('consultor_site.contact_retention_months')),
            'site_status' => $s->site_status,
            'credit_partner' => $partnerOn ? [
                'legal_name' => $partner['legal_name'], 'bp_registration' => $partner['bp_registration'], 'text' => $partner['text'] ?? null,
                'logo_url' => PublicFileUrl::absolute($s->credit_partner_logo_path),
            ] : null,
            'whatsapp' => $whatsapp ? ['number' => $whatsapp, 'messages' => $s->whatsappTexts()] : null,
            'socials' => $s->socials(),
            'parishes' => $parishes->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'dicofre' => $p->dicofre, 'municipality' => $p->municipality?->name,
                'market' => self::market($p->dicofre),
            ])->values()->all(),
            'measurement' => ['ga4_id' => $s->ga4_id ?: null, 'meta_pixel_id' => preg_match('/^[0-9]{6,20}$/', (string) $s->company?->facebook_pixel_id) ? $s->company->facebook_pixel_id : null],
            'colors' => $s->colorTokens(),
            'sections' => $s->sectionFlags(),
            'show_made_with' => (bool) $s->show_made_with,
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }

    /**
     * O valor mediano por m² do INE da freguesia, no período mais recente publicado; nada quando
     * o INE não o divulga (poucas vendas). Só a freguesia: o valor do concelho não sai no site.
     */
    private static function market(?string $dicofre): ?array
    {
        $row = IneHousingPrice::where('geo_level', 'freguesia')->where('official_code', (string) $dicofre)->orderByDesc('period_code')->first();
        if (! $dicofre || ! $row || ! $row->has_value || ! $row->median_m2) {
            return null;
        }

        return ['median_m2' => $row->median_m2, 'period' => $row->period_label, 'attribution' => $row->attribution()];
    }

    /** As fotografias das zonas, por ordem, com a freguesia quando é uma das de atuação. */
    private static function zonePhotos(ConsultantSite $s, Collection $parishes): array
    {
        $names = $parishes->pluck('name', 'id');

        return array_map(fn (array $p) => [
            'src' => PublicFileUrl::absolute($p['path']), 'src_960' => PublicFileUrl::absolute($p['path_960'] ?? null) ?? PublicFileUrl::absolute($p['path']),
            'width' => $p['width'] ?? null, 'height' => $p['height'] ?? null, 'alt' => $p['alt'] ?? null,
            'parish' => isset($p['parish_id']) ? ($names[$p['parish_id']] ?? null) : null,
        ], $s->zonePhotos());
    }

    public static function propertyCard(Property $p): array
    {
        $cover = $p->relationLoaded('images') ? $p->images->first() : $p->images()->first();

        return [
            'slug' => $p->siteSlug(),
            // Só na pré-visualização chegam rascunhos: o site mostra a etiqueta "Rascunho".
            'draft' => ! $p->isVisibleOnSite(),
            'reference' => $p->chave_nova_reference ?: $p->reference,
            'title' => $p->title,
            'kind' => $p->kind,
            'kind_label' => Property::KINDS[$p->kind] ?? $p->kind,
            'listing' => $p->listing,
            'typology' => $p->typology,
            'bedrooms' => $p->bedrooms,
            'bathrooms' => $p->bathrooms,
            'area_m2' => $p->area_m2,
            'gross_area_m2' => $p->gross_area_m2,
            'construction_state' => $p->construction_state,
            'price' => $p->price_on_request ? null : $p->price,
            'price_on_request' => (bool) $p->price_on_request,
            'energy_class' => $p->energy_class,
            'energy_precertificate' => (bool) $p->energy_precertificate,
            'availability' => self::availability($p),
            'featured' => (bool) $p->featured,
            'parish' => $p->parish ? ['name' => $p->parish->name, 'dicofre' => $p->parish->dicofre] : null,
            'municipality' => $p->parish?->municipality?->name,
            'location' => self::approxLocation($p),
            'short_description' => $p->short_description,
            'cover' => $cover ? self::image($cover) : null,
            'has_video' => (bool) $p->youtube_id,
            'youtube_id' => $p->youtube_id,
            'development' => $p->development && $p->development->site_status === 'publicado' ? ['slug' => $p->development->slug, 'name' => $p->development->name] : null,
            'published_at' => $p->published_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    public static function propertyDetail(Property $p): array
    {
        return self::propertyCard($p) + [
            'description' => $p->description,
            'images' => $p->images->map(fn (ListingImage $i) => self::image($i))->values()->all(),
            'youtube_id' => $p->youtube_id,
            'chave_nova' => $p->chave_nova_url ? ['url' => $p->chave_nova_url, 'reference' => $p->chave_nova_reference] : null,
        ];
    }

    public static function developmentCard(Development $d): array
    {
        $units = $d->relationLoaded('units') ? $d->units : $d->units()->visibleOnSite()->get();
        $available = $units->filter(fn (Property $u) => in_array($u->status, Property::AVAILABLE, true));
        $prices = $available->filter(fn (Property $u) => ! $u->price_on_request && $u->price > 0)->pluck('price');
        $cover = $d->relationLoaded('images') ? $d->images->first() : $d->images()->first();

        return [
            'slug' => $d->slug,
            'draft' => $d->site_status !== 'publicado',
            'name' => $d->name,
            'short_description' => $d->short_description,
            'parish' => $d->parish ? ['name' => $d->parish->name, 'dicofre' => $d->parish->dicofre] : null,
            'municipality' => $d->parish?->municipality?->name,
            'location' => $d->lat !== null && $d->lng !== null ? ['lat' => $d->lat, 'lng' => $d->lng, 'exact' => true] : null,
            'construction_phase' => $d->construction_phase,
            'construction_phase_label' => Development::PHASES[$d->construction_phase] ?? $d->construction_phase,
            'expected_completion' => $d->expected_completion?->format('Y-m'),
            'energy_class_expected' => $d->energy_class_expected,
            'featured' => (bool) $d->featured,
            'price_from' => $prices->isEmpty() ? null : (int) $prices->min(),
            'units_available' => $available->count(),
            'cover' => $cover ? self::image($cover) : null,
            'has_video' => (bool) $d->youtube_id,
            'youtube_id' => $d->youtube_id,
            'published_at' => $d->published_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    public static function developmentDetail(Development $d): array
    {
        return self::developmentCard($d) + [
            'description' => $d->description,
            'payment_plan' => $d->payment_plan,
            'images' => $d->images->map(fn (ListingImage $i) => self::image($i))->values()->all(),
            'youtube_id' => $d->youtube_id,
            'chave_nova' => $d->chave_nova_url ? ['url' => $d->chave_nova_url, 'reference' => $d->chave_nova_reference] : null,
            'units' => $d->units->map(fn (Property $u) => self::propertyCard($u))->values()->all(),
        ];
    }

    public static function blog(Blog $b, ConsultantSite $site): array
    {
        $data = (new BlogPublicResource($b))->resolve();
        unset($data['id']);
        foreach (['banner', 'og_image'] as $k) {
            $data[$k] = PublicFileUrl::absolute($data[$k] ?? null);
        }
        $data['category_slug'] = $b->category ? Str::slug($b->category) : null;
        $data['author'] = ['name' => $site->publicName()];

        return $data;
    }

    /** @param Collection<int, Blog> $blogs */
    public static function blogCard(Blog $b, ConsultantSite $site): array
    {
        $data = self::blog($b, $site);
        unset($data['content']);

        return $data;
    }

    public static function image(ListingImage $i): array
    {
        $u = fn ($p) => PublicFileUrl::absolute($p);

        return [
            'src' => $u($i->path),
            'srcset' => "{$u($i->path_480)} 480w, {$u($i->path_960)} 960w, {$u($i->path)} 1600w",
            'width' => $i->width, 'height' => $i->height, 'alt' => $i->alt,
        ];
    }

    private static function availability(Property $p): string
    {
        return $p->status === 'fechado' ? ($p->listing === 'arrendamento' ? 'arrendado' : 'vendido') : $p->status;
    }

    /** Usados: só o centro do círculo, arredondado a 3 casas (cerca de 100 m), com um raio de pelo menos 300 m. */
    private static function approxLocation(Property $p): ?array
    {
        if ($p->approx_lat === null || $p->approx_lng === null) {
            return null;
        }

        return ['lat' => round((float) $p->approx_lat, 3), 'lng' => round((float) $p->approx_lng, 3), 'radius_m' => max(300, (int) $p->approx_radius_m), 'exact' => false];
    }
}
