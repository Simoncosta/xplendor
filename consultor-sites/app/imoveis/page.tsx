import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { getProperties, getSite } from "@/lib/api";
import { euros } from "@/lib/format";
import { IMOVEIS_LISTA } from "@/content/textos";
import { PageIntro } from "@/components/page-intro";
import { PropertyCard } from "@/components/listing/property-card";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";

export const metadata: Metadata = { title: IMOVEIS_LISTA.titulo, description: IMOVEIS_LISTA.lead };

type Search = { tipo?: string; tipologia?: string; zona?: string; estado?: string; preco_min?: string; preco_max?: string; pagina?: string };
const PRICE_STEPS = [100000, 150000, 200000, 250000, 300000, 400000, 500000, 750000, 1000000];

/**
 * A lista dos imóveis, com filtros (tipo, tipologia, zona, preço, estado da construção). Os
 * filtros são um formulário GET: funcionam sem JavaScript e o endereço guarda a pesquisa.
 */
export default async function ImoveisPage({ searchParams }: { searchParams: Promise<Search> }) {
    const site = await getSite();
    if (!site) notFound();
    const s = await searchParams;
    const page = Math.max(1, Number(s.pagina) || 1);
    const data = await getProperties(site, {
        kind: s.tipo, typology: s.tipologia, parish: s.zona, construction_state: s.estado,
        price_min: s.preco_min, price_max: s.preco_max, page: String(page), per_page: "12",
    });
    const f = data.facets;
    const filtered = !!(s.tipo || s.tipologia || s.zona || s.estado || s.preco_min || s.preco_max);
    const qs = (p: number) => {
        const q = new URLSearchParams(Object.entries({ ...s, pagina: String(p) }).filter(([, v]) => v) as [string, string][]);
        return `/imoveis?${q.toString()}`;
    };
    const select = "mt-2 block w-full rounded-xs border border-navy/25 bg-white/60 px-3 py-2.5 text-[0.98rem]";
    const prices = PRICE_STEPS.filter((v) => (f.price_min === null || v >= f.price_min * 0.8) && (f.price_max === null || v <= f.price_max * 1.2));

    return (
        <>
            <PageIntro title={IMOVEIS_LISTA.titulo} lead={IMOVEIS_LISTA.lead} />
            <section className="container-site pb-24">
                {(data.total > 0 || filtered) && (
                    <form method="get" action="/imoveis" className="grid grid-cols-2 gap-x-4 gap-y-4 border-y border-line py-6 lg:grid-cols-6" aria-label={IMOVEIS_LISTA.filtros}>
                        <label className="text-sm font-semibold">{IMOVEIS_LISTA.tipo}
                            <select name="tipo" defaultValue={s.tipo ?? ""} className={select}>
                                <option value="">{IMOVEIS_LISTA.todos}</option>
                                {Object.entries(f.kinds).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                            </select>
                        </label>
                        <label className="text-sm font-semibold">{IMOVEIS_LISTA.tipologia}
                            <select name="tipologia" defaultValue={s.tipologia ?? ""} className={select}>
                                <option value="">{IMOVEIS_LISTA.todas}</option>
                                {f.typologies.map((t) => <option key={t} value={t}>{t}</option>)}
                            </select>
                        </label>
                        <label className="text-sm font-semibold">{IMOVEIS_LISTA.zona}
                            <select name="zona" defaultValue={s.zona ?? ""} className={select}>
                                <option value="">{IMOVEIS_LISTA.todas}</option>
                                {f.parishes.map((p) => <option key={p.dicofre} value={p.dicofre}>{p.municipality ? `${p.name}, ${p.municipality}` : p.name}</option>)}
                            </select>
                        </label>
                        <label className="text-sm font-semibold">{IMOVEIS_LISTA.estado}
                            <select name="estado" defaultValue={s.estado ?? ""} className={select}>
                                <option value="">{IMOVEIS_LISTA.todos}</option>
                                {Object.entries(f.construction_states).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                            </select>
                        </label>
                        <label className="text-sm font-semibold">{IMOVEIS_LISTA.precoMin}
                            <select name="preco_min" defaultValue={s.preco_min ?? ""} className={select}>
                                <option value="">{IMOVEIS_LISTA.todos}</option>
                                {prices.map((v) => <option key={v} value={v}>{euros(v)}</option>)}
                            </select>
                        </label>
                        <label className="text-sm font-semibold">{IMOVEIS_LISTA.precoMax}
                            <select name="preco_max" defaultValue={s.preco_max ?? ""} className={select}>
                                <option value="">{IMOVEIS_LISTA.todos}</option>
                                {prices.map((v) => <option key={v} value={v}>{euros(v)}</option>)}
                            </select>
                        </label>
                        <div className="col-span-2 flex flex-wrap items-center gap-4 lg:col-span-6">
                            <button type="submit" className={cn(buttonVariants({ variant: "primary", size: "md" }))}>{IMOVEIS_LISTA.aplicar}</button>
                            {filtered && <Link href="/imoveis" className="link-underline font-semibold">{IMOVEIS_LISTA.limpar}</Link>}
                            <p className="muted ml-auto text-sm" role="status">{IMOVEIS_LISTA.resultados(data.total)}</p>
                        </div>
                    </form>
                )}
                {data.items.length === 0 ? (
                    <div className="max-w-[48ch] py-16">
                        <p className="text-lead">{filtered ? IMOVEIS_LISTA.vazio : IMOVEIS_LISTA.vazioSemFiltros}</p>
                        {!filtered && <Link href="/contacto" className={cn(buttonVariants({ variant: "outline", size: "md" }), "mt-8")}>{IMOVEIS_LISTA.falarComigo}</Link>}
                    </div>
                ) : (
                    <ul className="mt-12 grid gap-x-8 gap-y-14 sm:grid-cols-2 lg:grid-cols-3">
                        {data.items.map((p) => <li key={p.slug}><PropertyCard p={p} /></li>)}
                    </ul>
                )}
                {data.last_page > 1 && (
                    <nav aria-label={IMOVEIS_LISTA.pagina(page, data.last_page)} className="mt-16 flex items-center justify-between border-t border-line pt-6">
                        {page > 1 ? <Link href={qs(page - 1)} className="link-underline font-semibold">{IMOVEIS_LISTA.anterior}</Link> : <span />}
                        <span className="muted text-sm">{IMOVEIS_LISTA.pagina(page, data.last_page)}</span>
                        {page < data.last_page ? <Link href={qs(page + 1)} className="link-underline font-semibold">{IMOVEIS_LISTA.seguinte}</Link> : <span />}
                    </nav>
                )}
            </section>
        </>
    );
}
