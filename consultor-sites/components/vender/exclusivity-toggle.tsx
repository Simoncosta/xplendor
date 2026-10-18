"use client";

import { useId, useState } from "react";
import { EXCLUSIVIDADE, VENDER } from "@/content/textos";
import { cn } from "@/lib/cn";

/**
 * Exclusividade como comparação interativa: um seletor "Sem exclusividade / Com exclusividade" que
 * mostra uma coluna de cada vez (os mesmos cinco temas), sem vistos nem cruzes.
 */
export function ExclusivityToggle() {
    const [withExclusive, setWithExclusive] = useState(true);
    const id = useId();
    const tabs = [{ on: false, label: EXCLUSIVIDADE.colunaSem }, { on: true, label: EXCLUSIVIDADE.colunaCom }];
    return (
        <section aria-labelledby="exclusividade-titulo" className="section bg-cream-2">
            <div className="container-site grid gap-12 md:grid-cols-12">
                <div className="md:col-span-5">
                    <h2 id="exclusividade-titulo" className="text-h2 max-w-[12ch]">{VENDER.exclusividade.titulo}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                    <p className="text-lead muted mt-6 max-w-[36ch]">{VENDER.exclusividade.frase}</p>
                </div>
                <div className="md:col-span-7">
                    <div role="tablist" aria-label={VENDER.exclusividade.comparar} className="inline-flex w-full border border-navy p-1 sm:w-auto">
                        {tabs.map((t) => (
                            <button key={t.label} type="button" role="tab" id={`${id}-${t.on}`} aria-selected={withExclusive === t.on} aria-controls={`${id}-painel`}
                                onClick={() => setWithExclusive(t.on)}
                                onKeyDown={(e) => { if (e.key === "ArrowLeft" || e.key === "ArrowRight") { e.preventDefault(); setWithExclusive((v) => !v); (document.getElementById(`${id}-${!t.on}`) as HTMLElement | null)?.focus(); } }}
                                tabIndex={withExclusive === t.on ? 0 : -1}
                                className={cn("min-h-11 flex-1 px-5 text-sm font-semibold transition-colors sm:flex-none", withExclusive === t.on ? (t.on ? "bg-amber text-navy" : "bg-navy text-cream") : "text-navy hover:bg-cream")}>
                                {t.label}
                            </button>
                        ))}
                    </div>
                    <dl id={`${id}-painel`} role="tabpanel" aria-labelledby={`${id}-${withExclusive}`} className="mt-8 border-t border-line">
                        {EXCLUSIVIDADE.linhas.map((l) => (
                            <div key={`${l.tema}-${withExclusive}`} className="market-in grid gap-1 border-b border-line py-5 sm:grid-cols-[12rem_1fr] sm:gap-6">
                                <dt className="font-display text-[1.2rem]">{l.tema}</dt>
                                <dd className={withExclusive ? "font-medium" : "muted"}>{withExclusive ? l.com : l.sem}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            </div>
        </section>
    );
}
