"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { MERCADO } from "@/content/textos";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";
import { thousands } from "@/lib/format";
import { shortParish } from "@/lib/home";
import type { Market, ZonePhoto } from "@/lib/types";

export type MarketItem = Market & { name: string };

/**
 * O mercado num número: o valor mediano por m² do INE de uma freguesia de atuação, em letra muito
 * grande, com o nome, o período e a atribuição do INE. Roda pelas freguesias com valor (6 s);
 * para ao escolher uma, com o botão de pausa e com movimento reduzido. Por trás, uma fotografia
 * de uma zona, quando existe.
 */
export function MarketNumber({ items, photo }: { items: MarketItem[]; photo: ZonePhoto | null }) {
    const [i, setI] = useState(0);
    const [paused, setPaused] = useState(false);
    const [auto, setAuto] = useState(false);

    useEffect(() => {
        if (items.length < 2 || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
        const start = window.setTimeout(() => setAuto(true), 0);
        return () => window.clearTimeout(start);
    }, [items.length]);
    useEffect(() => {
        if (!auto || paused) return;
        const t = window.setInterval(() => setI((x) => (x + 1) % items.length), 6000);
        return () => window.clearInterval(t);
    }, [auto, paused, items.length]);

    const cur = items[i] ?? items[0];
    if (!cur) return null;

    return (
        <section aria-labelledby="mercado-titulo" className="on-dark relative isolate overflow-hidden">
            {photo && (
                <div className="absolute inset-0 -z-10" aria-hidden="true">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={photo.src} srcSet={`${photo.src_960} 960w, ${photo.src} 2400w`} sizes="100vw" alt="" loading="lazy" decoding="async" className="slow-zoom h-full w-full object-cover" />
                    <div className="absolute inset-0 bg-navy/85" />
                </div>
            )}
            {/* No telemóvel: o título, o número e o botão; no computador, o botão fica por baixo do título. */}
            <div className="container-site section grid gap-x-12 gap-y-10 md:grid-cols-12 md:grid-rows-[auto_1fr]">
                <div className="md:col-span-5 md:row-start-1">
                    <h2 id="mercado-titulo" className="text-h2 max-w-[12ch]">{MERCADO.titulo}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                    <p className="text-lead muted mt-6 max-w-[34ch]">{MERCADO.frase}</p>
                </div>
                <div className="md:col-span-5 md:row-start-2 md:self-start max-md:order-2">
                    <Link href="/contacto?pedido=estudo-da-zona" className={cn(buttonVariants({ variant: "primary", size: "lg" }), "w-full sm:w-auto")}>{MERCADO.botao}</Link>
                </div>
                <div className="md:col-span-7 md:col-start-6 md:row-span-2 md:row-start-1 md:self-end max-md:order-1">
                    <div key={cur.name} className="market-in" aria-live={auto && !paused ? "off" : "polite"}>
                        <p className="font-display leading-[0.85] tracking-[-0.03em] text-[clamp(5rem,2.5rem+12vw,12.5rem)] tabular-nums">
                            {thousands(cur.median_m2)}<span className="ml-3 align-top font-sans text-[clamp(1.1rem,0.9rem+1vw,1.6rem)] font-semibold tracking-normal text-amber">{MERCADO.unidade}</span>
                        </p>
                        <p className="mt-6 max-w-[28ch] font-display text-h3">{shortParish(cur.name)}</p>
                        <p className="muted mt-1">{cur.period}</p>
                    </div>
                    {items.length > 1 && (
                        <div className="mt-8 flex flex-wrap items-center gap-2" role="group" aria-label={MERCADO.freguesias}>
                            {items.map((m, k) => (
                                <button key={m.name} type="button" aria-pressed={k === i} onClick={() => { setI(k); setPaused(true); }}
                                    className={cn("min-h-11 border px-4 text-sm transition-colors", k === i ? "border-amber bg-amber text-navy" : "border-line-dark hover:border-cream")}>
                                    {shortParish(m.name)}
                                </button>
                            ))}
                            {auto && (
                                <button type="button" onClick={() => setPaused((p) => !p)} className="link-underline ml-2 min-h-11 px-2 text-sm">
                                    {paused ? MERCADO.continuar : MERCADO.pausar}
                                </button>
                            )}
                        </div>
                    )}
                    <p className="mt-8 max-w-[70ch] text-xs leading-relaxed text-mist">{cur.attribution}</p>
                </div>
            </div>
        </section>
    );
}
