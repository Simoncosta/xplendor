import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getProperties, getSite } from "@/lib/api";
import { COMO_VENDO, VENDER } from "@/content/textos";
import { BuyersShort, StudyShort, VenderHero, VenderSteps, WhatIDo } from "@/components/vender/vender-blocks";
import { ExclusivityToggle } from "@/components/vender/exclusivity-toggle";
import { MarketNumber, type MarketItem } from "@/components/home/market-number";
import { ZoneBand } from "@/components/home/zone-band";
import { Network } from "@/components/sections/network";
import { Credit } from "@/components/sections/credit";
import { Faq } from "@/components/sections/faq";
import { ContactFinal } from "@/components/sections/contact-final";

export const metadata: Metadata = { title: VENDER.titulo, description: VENDER.lead };

/**
 * Vender: a mesma regra da página inicial (cada bloco com um título e uma frase, mais imagem). O
 * que faço pela casa, os 8 passos, a exclusividade como comparação, os compradores, o estudo da
 * zona e as perguntas frequentes (fechadas). Fotografias do consultor e das zonas como separadores.
 */
export default async function VenderPage() {
    const site = await getSite();
    if (!site) notFound();
    const p = site.profile;
    const on = (k: string) => p.sections[k] !== false;
    const zones = p.zone_photos ?? [];
    const first = (await getProperties(site, { per_page: "1" }).catch(() => null))?.items[0] ?? null;
    const market: MarketItem[] = (p.parishes ?? []).flatMap((x) => (x.market ? [{ ...x.market, name: x.name }] : []));

    return (
        <>
            <VenderHero image={zones[0]?.src ?? p.hero.poster_url} image960={zones[0]?.src_960 ?? p.hero.poster_960_url} />
            {on("marketing") && (
                <WhatIDo name={p.public_name} photo={p.photo_url} video={{ mp4: p.hero.video_mp4_url, webm: p.hero.video_webm_url }} poster={p.hero.poster_url}
                    ad={{ image: first?.cover?.src ?? zones[1]?.src_960 ?? p.hero.poster_960_url ?? p.hero.poster_url, title: first?.title ?? COMO_VENDO.anuncioTitulo }} />
            )}
            <VenderSteps />
            <ZoneBand photo={zones[1] ?? null} />
            {on("exclusivity") && <ExclusivityToggle />}
            {on("qualified_buyers") && <BuyersShort hasCreditPartner={!!p.credit_partner} profile={p} />}
            {on("network") && <Network network={p.network} />}
            {on("credit") && p.credit_partner && <Credit partner={p.credit_partner} />}
            {on("market_study") && (market.length > 0 ? <MarketNumber items={market} photo={zones[2] ?? null} /> : <StudyShort />)}
            <ZoneBand photo={zones[3] ?? null} />
            {on("faq") && <Faq />}
            <ContactFinal title={VENDER.contactoTitulo} text={VENDER.contactoTexto} defaultMessage={VENDER.mensagemPreenchida} />
        </>
    );
}
