import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { getDevelopment, getSite } from "@/lib/api";
import { areaM2, euros, monthYear, networkName } from "@/lib/format";
import { EMPREENDIMENTO, FICHA } from "@/content/textos";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";
import { Gallery } from "@/components/listing/gallery";
import { AvailabilityBadge, DraftBadge } from "@/components/listing/badges";
import { EnergyLabel } from "@/components/listing/energy-label";
import { Location } from "@/components/listing/location";
import { VideoOnTap } from "@/components/listing/video-on-tap";
import { NetworkLink } from "@/components/listing/network-link";
import { priceText } from "@/components/listing/property-card";
import { ContactForm } from "@/components/contact-form";
import { TrackView } from "@/components/track-view";
import type { DevelopmentDetail, Site } from "@/lib/types";

type Params = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Params): Promise<Metadata> {
    const site = await getSite();
    const { slug } = await params;
    const d = site ? await getDevelopment(site, slug) : null;
    if (!d) return {};
    return { title: d.name, description: d.short_description ?? EMPREENDIMENTO.lead, alternates: { canonical: `/empreendimentos/${d.slug}` },
        ...(d.draft ? { robots: { index: false, follow: false } } : {}) };
}

function jsonLd(d: DevelopmentDetail, site: Site) {
    return {
        "@context": "https://schema.org", "@type": "ApartmentComplex", name: d.name, description: d.short_description ?? undefined,
        url: `https://${site.profile.primary_host ?? site.host}/empreendimentos/${d.slug}`, image: d.images.map((i) => i.src).slice(0, 6),
        address: { "@type": "PostalAddress", addressLocality: d.parish?.name, addressRegion: d.municipality ?? undefined, addressCountry: "PT" },
        ...(d.location ? { geo: { "@type": "GeoCoordinates", latitude: d.location.lat, longitude: d.location.lng } } : {}),
        ...(d.price_from ? { offers: { "@type": "AggregateOffer", lowPrice: d.price_from, priceCurrency: "EUR", offerCount: d.units_available } } : {}),
    };
}

/** Um empreendimento em construção: as frações, a fase da obra, a data prevista, o plano de pagamentos e o ponto exato. */
export default async function DevelopmentPage({ params }: Params) {
    const site = await getSite();
    if (!site) notFound();
    const { slug } = await params;
    const d = await getDevelopment(site, slug);
    if (!d) notFound();
    const rede = networkName(site.profile.network.label);
    const place = [d.parish?.name, d.municipality].filter(Boolean).join(", ");
    const C = EMPREENDIMENTO.colunas;

    return (
        <>
            <TrackView subject="development" slug={d.slug} />
            <span hidden data-wa-empreendimento={d.name} />
            {!d.draft && <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd(d, site)).replace(/</g, "\\u003c") }} />}
            <div className="container-site pt-6 md:pt-10">
                <Link href="/empreendimentos" className="link-underline text-sm font-semibold">{EMPREENDIMENTO.titulo}</Link>
            </div>
            <div className="relative mx-auto mt-6 max-w-[96rem] md:px-10">
                <Gallery images={d.images} title={d.name} />
                {d.draft && <DraftBadge className="absolute left-0 top-4 text-base md:left-10" />}
            </div>

            <div className="container-site grid gap-12 pb-20 pt-10 md:grid-cols-12 md:pt-14">
                <div className="md:col-span-7">
                    <h1 className="text-h2 max-w-[18ch]">{d.name}</h1>
                    <p className="text-lead muted mt-4">{place}</p>
                    {d.short_description && <p className="text-lead mt-8 max-w-[56ch]">{d.short_description}</p>}
                    {d.description && <div className="mt-8 max-w-[62ch] space-y-4 whitespace-pre-line">{d.description}</div>}

                    <section aria-labelledby="fracoes" className="mt-14">
                        <h2 id="fracoes" className="font-display text-h3">{EMPREENDIMENTO.fracoes}</h2>
                        {d.units.length === 0 ? <p className="muted mt-6">{EMPREENDIMENTO.semFracoes}</p> : (
                            <div className="mt-6 overflow-x-auto">
                                <table className="w-full min-w-[34rem] border-collapse text-left">
                                    <thead><tr className="border-b border-navy text-sm">
                                        <th scope="col" className="pb-3 pr-4 font-semibold">{C.fracao}</th><th scope="col" className="pb-3 pr-4 font-semibold">{C.tipologia}</th>
                                        <th scope="col" className="pb-3 pr-4 font-semibold">{C.area}</th><th scope="col" className="pb-3 pr-4 text-right font-semibold">{C.preco}</th>
                                        <th scope="col" className="pb-3 font-semibold">{C.estado}</th>
                                    </tr></thead>
                                    <tbody>
                                        {d.units.map((u) => (
                                            <tr key={u.slug} className="border-b border-line">
                                                <td className="py-4 pr-4"><Link href={`/imoveis/${u.slug}`} className="link-underline font-semibold">{u.title}</Link>{u.draft && <span className="ml-2 align-middle text-xs font-bold uppercase text-navy [background:var(--color-amber)] px-1.5 py-0.5">{FICHA.rascunho}</span>}</td>
                                                <td className="py-4 pr-4">{u.typology}</td>
                                                <td className="py-4 pr-4">{areaM2(u.area_m2) ?? ""}</td>
                                                <td className="py-4 pr-4 text-right tabular-nums">{priceText(u)}</td>
                                                <td className="py-4">{u.availability === "disponivel" ? EMPREENDIMENTO.disponivel : <AvailabilityBadge availability={u.availability} className="px-2 py-0.5 text-xs" />}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    {d.payment_plan && (
                        <section aria-labelledby="plano" className="mt-14">
                            <h2 id="plano" className="font-display text-h3">{EMPREENDIMENTO.planoPagamentos}</h2>
                            <div className="mt-6 max-w-[62ch] whitespace-pre-line">{d.payment_plan}</div>
                        </section>
                    )}

                    <section className="mt-14 border-l-2 border-amber pl-6">
                        <h2 className="font-display text-h3"><Link href="/guias/comprar-na-planta" className="link-underline">{EMPREENDIMENTO.guia}</Link></h2>
                        <p className="muted mt-3 max-w-[56ch]">{EMPREENDIMENTO.guiaTexto}</p>
                    </section>

                    {d.youtube_id && (
                        <section aria-labelledby="video" className="mt-14">
                            <h2 id="video" className="font-display text-h3">{FICHA.video}</h2>
                            <div className="mt-6"><VideoOnTap youtubeId={d.youtube_id} cover={d.cover} title={d.name} subject="development" slug={d.slug} /></div>
                        </section>
                    )}

                    {d.location && (
                        <section aria-labelledby="localizacao" className="mt-14">
                            <h2 id="localizacao" className="font-display text-h3">{FICHA.localizacao}</h2>
                            <div className="mt-6"><Location lat={d.location.lat} lng={d.location.lng} radius={null} exact place={place} /></div>
                        </section>
                    )}
                </div>

                <aside className="md:col-span-4 md:col-start-9">
                    <dl className="border-t-2 border-navy pt-6 md:sticky md:top-28">
                        {d.price_from && <><dt className="muted text-sm">{EMPREENDIMENTO.desde}</dt><dd className="font-display text-[2.2rem] leading-tight">{euros(d.price_from)}</dd></>}
                        <dt className="muted mt-6 text-sm">{EMPREENDIMENTO.fase}</dt><dd className="font-semibold">{d.construction_phase_label}</dd>
                        {d.expected_completion && <><dt className="muted mt-4 text-sm">{EMPREENDIMENTO.conclusao}</dt><dd className="font-semibold">{monthYear(d.expected_completion)}</dd></>}
                        <dt className="muted mt-4 text-sm">{EMPREENDIMENTO.fracoes}</dt><dd className="font-semibold">{EMPREENDIMENTO.fracoesDisponiveis(d.units_available)}</dd>
                        {d.energy_class_expected && <><dt className="sr-only">{EMPREENDIMENTO.energiaPrevista}</dt><dd className="mt-6"><EnergyLabel value={d.energy_class_expected} /><p className="muted mt-2 text-xs">{EMPREENDIMENTO.energiaPrevista}</p></dd></>}
                        <dd className="mt-8 flex flex-col gap-3">
                            <a href="#visitar" className={cn(buttonVariants({ variant: "primary", size: "lg" }))}>{FICHA.quero}</a>
                            {d.chave_nova && <NetworkLink href={d.chave_nova.url} label={FICHA.verNaRede(rede)} subject="development" slug={d.slug} />}
                        </dd>
                    </dl>
                </aside>
            </div>

            <section id="visitar" aria-labelledby="visitar-titulo" className="section on-dark">
                <div className="container-site grid gap-12 md:grid-cols-12">
                    <div className="md:col-span-5">
                        <h2 id="visitar-titulo" className="text-h2">{FICHA.visitaTitulo}</h2>
                        <span className="accent-rule" aria-hidden="true" />
                        <p className="text-lead muted mt-8 max-w-[32ch]">{FICHA.visitaTexto}</p>
                        <p className="mt-6 font-semibold">{d.name}</p>
                    </div>
                    <ContactForm tone="dark" kind="visita" developmentSlug={d.slug} submitLabel={FICHA.pedirVisita} className="md:col-span-6 md:col-start-7" />
                </div>
            </section>
        </>
    );
}
