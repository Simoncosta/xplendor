<?php

declare(strict_types=1);

namespace App\Services\ConsultantSite;

use App\Jobs\RevalidateConsultantSiteJob;
use App\Models\Blog;
use App\Models\ConsultantSite;
use App\Models\Development;
use App\Models\ListingImage;
use App\Models\Property;
use App\Support\ExternalCalls;

/**
 * Site do consultor: quando um imóvel, um empreendimento, uma fotografia, o perfil ou um artigo
 * do blog muda, pede ao site (pela rede interna, com segredo) que atualize essas páginas. Só nas
 * empresas com perfil do site. As etiquetas são as do site; o site junta-lhes o host.
 */
final class SiteRevalidation
{
    private const INTEREST_FIELDS = ['contacts_30', 'visits_30', 'contacts_30_manual', 'visits_30_manual', 'contacts_30_site', 'visits_30_site', 'interest_updated_at', 'updated_at'];

    public static function register(): void
    {
        $property = function (Property $p) {
            // As contagens de interesse não aparecem no site: não há páginas a atualizar.
            $changed = array_keys($p->getChanges()); // vazio ao criar e ao apagar
            if ($changed !== [] && ! $p->trashed() && array_diff($changed, self::INTEREST_FIELDS) === []) {
                return;
            }
            $tags = ['properties', 'developments'];
            foreach (array_unique(array_filter([$p->slug, $p->getOriginal('slug')])) as $slug) {
                $tags[] = "property:{$slug}";
            }
            if ($p->development_id || $p->getOriginal('development_id')) {
                foreach (Development::withTrashed()->whereIn('id', array_filter([$p->development_id, $p->getOriginal('development_id')]))->pluck('slug') as $slug) {
                    $tags[] = "development:{$slug}";
                }
            }
            self::dispatch((int) $p->company_id, $tags);
        };
        Property::saved($property);
        Property::deleted($property);

        $development = function (Development $d) {
            $tags = ['developments', 'properties'];
            foreach (array_unique(array_filter([$d->slug, $d->getOriginal('slug')])) as $slug) {
                $tags[] = "development:{$slug}";
            }
            self::dispatch((int) $d->company_id, $tags);
        };
        Development::saved($development);
        Development::deleted($development);

        $image = function (ListingImage $i) {
            if ($i->owner_type === ListingImage::OWNER_PROPERTY) {
                $slug = Property::withTrashed()->whereKey($i->owner_id)->value('slug');
                self::dispatch((int) $i->company_id, array_filter(['properties', 'developments', $slug ? "property:{$slug}" : null]));
            } else {
                $slug = Development::withTrashed()->whereKey($i->owner_id)->value('slug');
                self::dispatch((int) $i->company_id, array_filter(['developments', $slug ? "development:{$slug}" : null]));
            }
        };
        ListingImage::saved($image);
        ListingImage::deleted($image);

        ConsultantSite::saved(fn (ConsultantSite $s) => self::dispatch((int) $s->company_id, ['site']));

        $blog = function (Blog $b) {
            $tags = ['blog'];
            foreach (array_unique(array_filter([$b->slug, $b->getOriginal('slug')])) as $slug) {
                $tags[] = "blog:{$slug}";
            }
            self::dispatch((int) $b->company_id, $tags);
        };
        Blog::saved($blog);
        Blog::deleted($blog);
    }

    /** @param string[] $tags */
    public static function dispatch(int $companyId, array $tags): void
    {
        if ((string) config('consultor_site.revalidate_url') === '') {
            return; // desligada (dev e testes por omissão)
        }
        if (! ExternalCalls::allowed($companyId)) {
            return; // interruptor das chamadas externas desligado (dev)
        }
        $siteId = ConsultantSite::where('company_id', $companyId)->value('id');
        if ($siteId) {
            RevalidateConsultantSiteJob::dispatch((int) $siteId, array_values(array_unique($tags)))->afterCommit();
        }
    }
}
