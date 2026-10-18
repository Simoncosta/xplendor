import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getDevelopments, getSite } from "@/lib/api";
import { EMPREENDIMENTO } from "@/content/textos";
import { PageIntro } from "@/components/page-intro";
import { DevelopmentRow } from "@/components/listing/development-card";

export const metadata: Metadata = { title: EMPREENDIMENTO.titulo, description: EMPREENDIMENTO.lead };

export default async function EmpreendimentosPage() {
    const site = await getSite();
    if (!site) notFound();
    const items = await getDevelopments(site);
    return (
        <>
            <PageIntro title={EMPREENDIMENTO.titulo} lead={EMPREENDIMENTO.lead} />
            <section className="container-site pb-24">
                {items.length === 0 ? <p className="text-lead max-w-[48ch] py-10">{EMPREENDIMENTO.vazio}</p> : (
                    <ul className="space-y-20 border-t border-line pt-14">
                        {items.map((d, i) => <li key={d.slug}><DevelopmentRow d={d} flip={i % 2 === 1} /></li>)}
                    </ul>
                )}
            </section>
        </>
    );
}
