import Link from "next/link";
import { DESTAQUES, EMPREENDIMENTOS } from "@/content/textos";
import type { DevelopmentCard, PropertyCard as Card } from "@/lib/types";
import { SectionTitle } from "@/components/ui/section-title";
import { Reveal } from "@/components/motion";
import { PropertyCard } from "@/components/listing/property-card";
import { DevelopmentRow } from "@/components/listing/development-card";

/** Imóveis em destaque (só com imóveis publicados): uma composição assimétrica, o primeiro grande; o acesso à lista e aos vídeos. */
export function FeaturedProperties({ items, hasVideos }: { items: Card[]; hasVideos: boolean }) {
    if (items.length === 0) return null;
    return (
        <section aria-labelledby="destaques-titulo" className="section">
            <div className="container-site">
                <div className="flex flex-wrap items-end justify-between gap-6">
                    <SectionTitle id="destaques-titulo">{DESTAQUES.titulo}</SectionTitle>
                    <div className="flex gap-6 font-semibold">
                        <Link href="/imoveis" className="link-underline">{DESTAQUES.verTodos}</Link>
                        {hasVideos && <Link href="/videos" className="link-underline">{DESTAQUES.verVideos}</Link>}
                    </div>
                </div>
                <ul className="mt-14 grid gap-x-8 gap-y-14 md:grid-cols-12">
                    {items.slice(0, 5).map((p, i) => (
                        <Reveal as="li" key={p.slug} delay={i * 0.05} className={i === 0 ? "md:col-span-7 md:row-span-2" : "md:col-span-5"}>
                            <PropertyCard p={p} large={i === 0} />
                        </Reveal>
                    ))}
                </ul>
            </div>
        </section>
    );
}

/** Empreendimentos em construção (só com empreendimentos publicados). */
export function Developments({ items }: { items: DevelopmentCard[] }) {
    if (items.length === 0) return null;
    return (
        <section aria-labelledby="empreendimentos-titulo" className="section bg-cream-2">
            <div className="container-site">
                <SectionTitle id="empreendimentos-titulo">{EMPREENDIMENTOS.titulo}</SectionTitle>
                <ul className="mt-14 space-y-16">
                    {items.slice(0, 3).map((d, i) => <Reveal as="li" key={d.slug}><DevelopmentRow d={d} flip={i % 2 === 1} /></Reveal>)}
                </ul>
            </div>
        </section>
    );
}
