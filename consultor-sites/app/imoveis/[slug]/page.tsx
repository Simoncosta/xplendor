import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { getProperty, getSite } from "@/lib/api";
import { areaM2, networkName } from "@/lib/format";
import { FICHA } from "@/content/textos";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";
import { Gallery } from "@/components/listing/gallery";
import { AvailabilityBadge, DraftBadge } from "@/components/listing/badges";
import { EnergyLabel } from "@/components/listing/energy-label";
import { Location } from "@/components/listing/location";
import { VideoOnTap } from "@/components/listing/video-on-tap";
import { NetworkLink } from "@/components/listing/network-link";
import { WhatsAppButton } from "@/components/listing/whatsapp-button";
import { fillMessage } from "@/lib/whatsapp";
import { factsLine, priceText } from "@/components/listing/property-card";
import { ContactForm } from "@/components/contact-form";
import { TrackView } from "@/components/track-view";
import type { PropertyDetail, Site } from "@/lib/types";

type Params = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Params): Promise<Metadata> {
    const site = await getSite();
    const { slug } = await params;
    const p = site ? await getProperty(site, slug) : null;
    if (!p) return {};
    return {
        title: p.title,
        description: p.short_description ?? factsLine(p),
        alternates: { canonical: `/imoveis/${p.slug}` },
        ...(p.draft ? { robots: { index: false, follow: false } } : {}),
    };
}

/** Os dados estruturados do anúncio (schema.org), sem a morada: só a freguesia e o concelho. */
function jsonLd(p: PropertyDetail, site: Site) {
    const kind = p.kind === "moradia" ? "SingleFamilyResidence" : p.kind === "apartamento" ? "Apartment" : "Place";
    return {
        "@context": "https://schema.org",
        "@type": "RealEstateListing",
        name: p.title,
        description: p.short_description ?? undefined,
        url: `https://${site.profile.primary_host ?? site.host}/imoveis/${p.slug}`,
        image: p.images.map((i) => i.src).slice(0, 6),
        datePosted: p.published_at ?? undefined,
        offers: p.price_on_request || p.price === null ? undefined : {
            "@type": "Offer", price: p.price, priceCurrency: "EUR",
            availability: p.availability === "disponivel" ? "https://schema.org/InStock" : "https://schema.org/SoldOut",
            businessFunction: p.listing === "arrendamento" ? "http://purl.org/goodrelations/v1#LeaseOut" : "http://purl.org/goodrelations/v1#Sell",
        },
        about: {
            "@type": kind,
            ...(p.bedrooms !== null && kind !== "Place" ? { numberOfBedrooms: p.bedrooms } : {}),
            ...(p.bathrooms !== null && kind !== "Place" ? { numberOfBathroomsTotal: p.bathrooms } : {}),
            ...(p.area_m2 ? { floorSize: { "@type": "QuantitativeValue", value: p.area_m2, unitCode: "MTK" } } : {}),
            address: { "@type": "PostalAddress", addressLocality: p.parish?.name, addressRegion: p.municipality ?? undefined, addressCountry: "PT" },
        },
        broker: { "@type": "RealEstateAgent", name: site.profile.public_name },
    };
}

export default async function PropertyPage({ params }: Params) {
    const site = await getSite();
    if (!site) notFound();
    const { slug } = await params;
    const p = await getProperty(site, slug);
    if (!p) notFound();
    const { brokerage, network } = site.profile;
    const rede = networkName(network.label);
    const place = [p.parish?.name, p.municipality].filter(Boolean).join(", ");
    const facts: [string, string | null][] = [
        [FICHA.campos.tipo, p.kind_label],
        [FICHA.campos.negocio, FICHA.negocio[p.listing] ?? null],
        [FICHA.campos.tipologia, p.typology],
        [FICHA.campos.quartos, p.bedrooms !== null ? String(p.bedrooms) : null],
        [FICHA.campos.casasDeBanho, p.bathrooms !== null ? String(p.bathrooms) : null],
        [FICHA.campos.areaUtil, areaM2(p.area_m2)],
        [FICHA.campos.areaBruta, areaM2(p.gross_area_m2)],
        [FICHA.campos.estado, p.construction_state ? FICHA.estados[p.construction_state] ?? null : null],
        [FICHA.campos.freguesia, p.parish?.name ?? null],
        [FICHA.campos.concelho, p.municipality],
    ];
    const development = p.development;

    return (
        <>
            <TrackView subject="property" slug={p.slug} />
            <span hidden data-wa-referencia={p.reference} />
            {!p.draft && <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd(p, site)).replace(/</g, "\\u003c") }} />}
            <div className="container-site pt-6 md:pt-10">
                <Link href="/imoveis" className="link-underline text-sm font-semibold">{FICHA.voltar}</Link>
            </div>
            <div className="mx-auto mt-6 max-w-[96rem] md:px-10">
                <div className="relative">
                    <Gallery images={p.images} title={p.title} />
                    <div className="absolute left-0 top-4 flex flex-col items-start gap-1.5 md:left-0">
                        {p.draft && <DraftBadge className="text-base" />}
                        <AvailabilityBadge availability={p.availability} />
                    </div>
                </div>
            </div>

            <div className="container-site grid gap-12 pb-20 pt-10 md:grid-cols-12 md:pt-14">
                <div className="md:col-span-7">
                    <h1 className="text-h2 max-w-[20ch]">{p.title}</h1>
                    <p className="text-lead muted mt-4">{factsLine(p)}</p>
                    {p.short_description && <p className="text-lead mt-8 max-w-[56ch]">{p.short_description}</p>}

                    <section aria-labelledby="caracteristicas" className="mt-14">
                        <h2 id="caracteristicas" className="font-display text-h3">{FICHA.caracteristicas}</h2>
                        <dl className="mt-6 grid grid-cols-2 border-t border-line sm:grid-cols-3">
                            {facts.filter(([, v]) => v).map(([k, v]) => (
                                <div key={k} className="border-b border-line py-4 pr-4">
                                    <dt className="muted text-sm">{k}</dt>
                                    <dd className="mt-1 font-semibold">{v}</dd>
                                </div>
                            ))}
                            {development && (
                                <div className="border-b border-line py-4 pr-4">
                                    <dt className="muted text-sm">{FICHA.campos.empreendimento}</dt>
                                    <dd className="mt-1 font-semibold"><Link href={`/empreendimentos/${development.slug}`} className="link-underline">{development.name}</Link></dd>
                                </div>
                            )}
                        </dl>
                    </section>

                    {p.description && (
                        <section aria-labelledby="descricao" className="mt-14">
                            <h2 id="descricao" className="font-display text-h3">{FICHA.descricao}</h2>
                            <div className="mt-6 max-w-[62ch] space-y-4 whitespace-pre-line">{p.description}</div>
                        </section>
                    )}

                    {p.youtube_id && (
                        <section aria-labelledby="video" className="mt-14">
                            <h2 id="video" className="font-display text-h3">{FICHA.video}</h2>
                            <div className="mt-6"><VideoOnTap youtubeId={p.youtube_id} cover={p.cover} title={p.title} subject="property" slug={p.slug} /></div>
                        </section>
                    )}

                    {p.location && (
                        <section aria-labelledby="localizacao" className="mt-14">
                            <h2 id="localizacao" className="font-display text-h3">{FICHA.localizacao}</h2>
                            <div className="mt-6"><Location lat={p.location.lat} lng={p.location.lng} radius={p.location.radius_m} exact={false} place={place} /></div>
                        </section>
                    )}
                </div>

                <aside className="md:col-span-4 md:col-start-9">
                    <div className="border-t-2 border-navy pt-6 md:sticky md:top-28">
                        <p className="font-display text-[2.2rem] leading-tight">{priceText(p)}</p>
                        <EnergyLabel value={p.energy_class} precertificate={p.energy_precertificate} className="mt-6" />
                        <p className="muted mt-6 text-sm">{FICHA.referencia} {p.reference}</p>
                        {brokerage.legal_name && brokerage.ami && <p className="muted mt-1 text-sm">{FICHA.mediacao(brokerage.legal_name, brokerage.ami)}</p>}
                        <div className="mt-8 flex flex-col gap-3">
                            <a href="#visitar" className={cn(buttonVariants({ variant: "primary", size: "lg" }))}>{FICHA.quero}</a>
                            {site.profile.whatsapp ? (
                                <WhatsAppButton number={site.profile.whatsapp.number} label={FICHA.whatsapp} subject="property" slug={p.slug}
                                    message={fillMessage(site.profile.whatsapp.messages.property.prefill, { nome: site.profile.public_name.split(/\s+/)[0] ?? "", referencia: p.reference })} />
                            ) : process.env.NODE_ENV !== "production" ? (
                                <p className="border-[1.5px] border-dashed border-current p-3 text-center text-xs opacity-70">{FICHA.whatsapp}: número por configurar, o telemóvel da empresa em Empresa › Dados (dev)</p>
                            ) : null}
                            {p.chave_nova && <NetworkLink href={p.chave_nova.url} label={FICHA.verNaRede(rede)} subject="property" slug={p.slug} />}
                        </div>
                    </div>
                </aside>
            </div>

            <section id="visitar" aria-labelledby="visitar-titulo" className="section on-dark">
                <div className="container-site grid gap-12 md:grid-cols-12">
                    <div className="md:col-span-5">
                        <h2 id="visitar-titulo" className="text-h2">{FICHA.visitaTitulo}</h2>
                        <span className="accent-rule" aria-hidden="true" />
                        <p className="text-lead muted mt-8 max-w-[32ch]">{FICHA.visitaTexto}</p>
                        <p className="mt-6 font-semibold">{p.title}</p>
                        <p className="muted text-sm">{FICHA.referencia} {p.reference}</p>
                    </div>
                    <ContactForm tone="dark" kind="visita" propertySlug={p.slug} submitLabel={FICHA.pedirVisita} className="md:col-span-6 md:col-start-7" />
                </div>
            </section>
        </>
    );
}
