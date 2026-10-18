import { notFound } from "next/navigation";
import { getDevelopments, getFeaturedProperties, getProperties, getSite } from "@/lib/api";
import { Hero } from "@/components/sections/hero";
import { Developments, FeaturedProperties } from "@/components/sections/listings";
import { ShortsRow } from "@/components/home/shorts-row";
import { MarketNumber, type MarketItem } from "@/components/home/market-number";
import { HowISell } from "@/components/home/how-i-sell";
import { ZoneBand } from "@/components/home/zone-band";
import { AboutShort } from "@/components/home/about-short";
import { ContactShort } from "@/components/home/contact-short";
import { COMO_VENDO } from "@/content/textos";

/** Uma secção opcional que falha não deita a página abaixo: fica escondida. */
const safe = <T,>(p: Promise<T[]>) => p.catch(() => [] as T[]);

/**
 * A página inicial: mostrar em vez de explicar. Cada bloco tem um título e, no máximo, uma frase;
 * o resto está na página Vender. Pela ordem: o topo, os vídeos, os imóveis e os empreendimentos
 * (quando há), o mercado num número, como vendo, uma zona, quem sou e o contacto. Cada bloco tem
 * o seu interruptor no perfil e fica escondido sem conteúdo real.
 */
export default async function Home() {
    const site = await getSite();
    if (!site) notFound();
    const p = site.profile;
    const on = (k: string) => p.sections[k] !== false;
    const [featured, developments, all] = await Promise.all([
        on("featured_properties") ? safe(getFeaturedProperties(site)) : Promise.resolve([]),
        on("developments") ? safe(getDevelopments(site)) : Promise.resolve([]),
        getProperties(site, { per_page: "48" }).catch(() => null),
    ]);
    const properties = featured.length ? featured : (all?.items ?? []).slice(0, 5);
    const hasProperties = (all?.total ?? 0) > 0;
    const hasVideos = (all?.items ?? []).some((x) => x.youtube_id) || developments.some((d) => d.youtube_id);
    const market: MarketItem[] = (p.parishes ?? []).flatMap((x) => (x.market ? [{ ...x.market, name: x.name }] : []));
    const zones = p.zone_photos ?? [];
    const firstName = p.public_name.split(/\s+/)[0] ?? p.public_name;
    // O anúncio da composição: a capa de um imóvel publicado; sem imóveis, uma fotografia do consultor (zona ou capa do topo).
    const adImage = properties[0]?.cover?.src ?? zones[0]?.src_960 ?? p.hero.poster_url;
    const adTitle = properties[0]?.title ?? COMO_VENDO.anuncioTitulo;

    return (
        <>
            <Hero profile={p} hasProperties={hasProperties} howOnPage={on("process")} />
            {on("featured_videos") && p.featured_videos.length > 0 && <ShortsRow videos={p.featured_videos} />}
            {on("featured_properties") && <FeaturedProperties items={properties} hasVideos={hasVideos} />}
            {on("developments") && <Developments items={developments} />}
            {on("market_number") && market.length > 0 && <MarketNumber items={market} photo={zones[0] ?? null} />}
            {on("process") && (
                <HowISell name={p.public_name} photo={p.photo_url} video={{ mp4: p.hero.video_mp4_url, webm: p.hero.video_webm_url }} poster={p.hero.poster_url}
                    ad={{ image: adImage, title: adTitle }} />
            )}
            <ZoneBand photo={zones[1] ?? null} />
            {on("about") && <AboutShort profile={p} />}
            {on("contact") && <ContactShort whatsapp={p.whatsapp?.number ?? null} firstName={firstName} prefill={p.whatsapp?.messages.general.prefill ?? ""} photo={zones[2] ?? null} />}
        </>
    );
}
