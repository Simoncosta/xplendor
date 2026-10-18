import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getSite } from "@/lib/api";
import { LEGAL, RODAPE } from "@/content/textos";
import { Missing, PageIntro } from "@/components/page-intro";

export const metadata: Metadata = { title: LEGAL.titulo, description: LEGAL.livroTexto };

/** A informação legal: a mediação e a AMI, o livro de reclamações e a resolução alternativa de litígios. */
export default async function InformacaoLegalPage() {
    const site = await getSite();
    if (!site) notFound();
    const { brokerage: b, legal_links: l } = site.profile;
    const block = "grid gap-4 border-t border-line py-10 md:grid-cols-12";
    return (
        <>
            <PageIntro title={LEGAL.titulo} />
            <div className="container-site pb-24">
                <section className={block}>
                    <h2 className="font-display text-h3 md:col-span-4">{LEGAL.mediacao}</h2>
                    <p className="md:col-span-7 md:col-start-6">{b.legal_name && b.ami ? RODAPE.ami(b.legal_name, b.ami) : <Missing what={LEGAL.marcadores.mediacao} />}</p>
                </section>
                <section className={block}>
                    <h2 className="font-display text-h3 md:col-span-4">{LEGAL.livro}</h2>
                    <div className="md:col-span-7 md:col-start-6">
                        <p>{LEGAL.livroTexto}</p>
                        <p className="mt-3">{l.complaints_book_url
                            ? <a href={l.complaints_book_url} className="link-underline font-semibold" target="_blank" rel="noopener noreferrer">{LEGAL.livroLigacao}</a>
                            : <Missing what={LEGAL.marcadores.livro} />}</p>
                    </div>
                </section>
                <section id="resolucao-de-litigios" className={block}>
                    <h2 className="font-display text-h3 md:col-span-4">{LEGAL.ral}</h2>
                    <div className="md:col-span-7 md:col-start-6">
                        <p>{LEGAL.ralTexto}</p>
                        {l.ral.length ? (
                            <ul className="mt-4 space-y-2">{l.ral.map((e) => <li key={e.url}><a href={e.url} className="link-underline" target="_blank" rel="noopener noreferrer">{e.name}</a></li>)}</ul>
                        ) : <p className="mt-4"><Missing what={LEGAL.marcadores.ral} /></p>}
                        <p className="muted mt-4">{LEGAL.ralPortal}</p>
                    </div>
                </section>
                {l.legal_info_text && (
                    <section className={block}><div className="whitespace-pre-line md:col-span-7 md:col-start-6">{l.legal_info_text}</div></section>
                )}
                <section className={block}>
                    <h2 className="font-display text-h3 md:col-span-4">{LEGAL.plataforma}</h2>
                    <p className="md:col-span-7 md:col-start-6">{LEGAL.plataformaTexto}</p>
                </section>
            </div>
        </>
    );
}
