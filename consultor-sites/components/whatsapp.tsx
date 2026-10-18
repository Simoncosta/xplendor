"use client";

import { AnimatePresence, m } from "motion/react";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { WHATSAPP } from "@/content/textos";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";
import type { WhatsappTexts } from "@/lib/types";
import { track } from "@/lib/track";

type Ctx = "general" | "property" | "development" | "buy";
const NUDGE_KEY = "xp_wa_nudge";

/** O contexto da mensagem pela página: um imóvel, um empreendimento, comprar, ou o geral (e vender). */
function contextOf(pathname: string): Ctx {
    if (/^\/imoveis\/[^/]+/.test(pathname)) return "property";
    if (/^\/empreendimentos\/[^/]+/.test(pathname)) return "development";
    if (pathname.startsWith("/comprar")) return "buy";
    return "general";
}

/**
 * O botão do WhatsApp (canto inferior direito): abre um pequeno popup com a fotografia do
 * consultor e uma mensagem conforme a página, e o link para o WhatsApp com a mensagem preenchida.
 * Um aviso discreto junto ao botão, no máximo uma vez por sessão, ao fim de 15 segundos; fecha-se
 * e nunca aparece com um formulário à vista. Sem promessas de tempo de resposta.
 */
export function WhatsApp({ number, name, photoUrl, messages }: { number: string | null; name: string; photoUrl: string | null; messages: Record<Ctx, WhatsappTexts> }) {
    const pathname = usePathname();
    const ctx = contextOf(pathname);
    const [open, setOpen] = useState(false);
    const [nudge, setNudge] = useState(false);
    const panel = useRef<HTMLDivElement>(null);
    const firstName = name.split(/\s+/)[0] ?? name;
    const text = messages[ctx] ?? messages.general;

    useEffect(() => {
        let seen = false;
        try { seen = sessionStorage.getItem(NUDGE_KEY) === "1"; } catch { /* sem armazenamento: sem aviso */ seen = true; }
        if (seen) return;
        const t = window.setTimeout(() => {
            const formVisible = Array.from(document.querySelectorAll("form")).some((f) => {
                const r = f.getBoundingClientRect();
                return r.top < window.innerHeight && r.bottom > 0;
            });
            const typing = document.activeElement instanceof HTMLInputElement || document.activeElement instanceof HTMLTextAreaElement;
            if (formVisible || typing) return;
            setNudge(true);
            try { sessionStorage.setItem(NUDGE_KEY, "1"); } catch { /* ignorar */ }
        }, 15000);
        return () => window.clearTimeout(t);
    }, []);

    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") setOpen(false); };
        window.addEventListener("keydown", onKey);
        panel.current?.querySelector<HTMLElement>("a,button")?.focus();
        return () => window.removeEventListener("keydown", onKey);
    }, [open]);

    const fill = (s: string) => {
        const ref = document.querySelector<HTMLElement>("[data-wa-referencia]")?.dataset.waReferencia ?? "";
        const dev = document.querySelector<HTMLElement>("[data-wa-empreendimento]")?.dataset.waEmpreendimento ?? "";
        return s.replaceAll("{nome}", firstName).replaceAll("{referencia}", ref).replaceAll("{empreendimento}", dev);
    };
    // O mesmo endereço no servidor e no navegador (só o nome); a referência junta-se no clique.
    const href = number ? `https://wa.me/${number.replace(/[^0-9]/g, "")}?text=${encodeURIComponent(text.prefill.replaceAll("{nome}", firstName))}` : null;
    const initials = name.split(/\s+/).map((p) => p[0]).slice(0, 2).join("");

    return (
        <div className="fixed bottom-4 right-4 z-40 flex flex-col items-end gap-3">
            <AnimatePresence>
                {open && (
                    <m.div ref={panel} role="dialog" aria-label={WHATSAPP.botao} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: 8 }} transition={{ duration: 0.25 }}
                        className="w-[min(21rem,calc(100vw-2rem))] rounded-sm border border-line bg-cream p-5 text-navy shadow-paper">
                        <div className="flex items-start gap-3">
                            <div className="h-14 w-14 shrink-0 overflow-hidden rounded-full bg-cream-2">
                                {/* eslint-disable-next-line @next/next/no-img-element */}
                                {photoUrl ? <img src={photoUrl} alt="" className="h-full w-full object-cover object-top" /> : <span className="flex h-full w-full items-center justify-center font-display text-lg">{initials}</span>}
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="font-semibold">{name}</p>
                                <p className="mt-1 text-[0.95rem] leading-relaxed">{text.popup}</p>
                            </div>
                            <button type="button" onClick={() => setOpen(false)} aria-label={WHATSAPP.fechar} className="-mr-2 -mt-2 inline-flex h-9 w-9 items-center justify-center">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19" stroke="currentColor" strokeWidth="1.8" /></svg>
                            </button>
                        </div>
                        {href ? (
                            <a href={href} target="_blank" rel="noopener noreferrer" className={cn(buttonVariants({ variant: "primary", size: "sm" }), "mt-4 w-full")}
                                onClick={(e) => {
                                    e.currentTarget.href = `https://wa.me/${number!.replace(/[^0-9]/g, "")}?text=${encodeURIComponent(fill(text.prefill))}`;
                                    const slug = pathname.split("/")[2];
                                    if (ctx === "property" && slug) track("whatsapp_click", "property", slug);
                                    else if (ctx === "development" && slug) track("whatsapp_click", "development", slug);
                                    else track("whatsapp_click", "site");
                                }}>
                                {WHATSAPP.abrir}
                            </a>
                        ) : process.env.NODE_ENV !== "production"
                            ? <p className="mt-4 border-[1.5px] border-dashed border-current p-3 text-center text-xs opacity-75">Número por configurar: o telemóvel da empresa, em Empresa › Dados (dev)</p>
                            : null}
                    </m.div>
                )}
            </AnimatePresence>
            <AnimatePresence>
                {nudge && !open && (
                    <m.div initial={{ opacity: 0, x: 8 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0 }} transition={{ duration: 0.3 }}
                        className="flex max-w-[16rem] items-start gap-2 rounded-sm border border-line bg-cream py-3 pl-4 pr-2 text-sm text-navy shadow-paper">
                        <button type="button" className="text-left" onClick={() => { setNudge(false); setOpen(true); }}>{text.popup.split(/(?<=[.?!])\s/)[0]}</button>
                        <button type="button" onClick={() => setNudge(false)} aria-label={WHATSAPP.fechar} className="inline-flex h-6 w-6 shrink-0 items-center justify-center">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19" stroke="currentColor" strokeWidth="2" /></svg>
                        </button>
                    </m.div>
                )}
            </AnimatePresence>
            <button type="button" onClick={() => { setOpen((o) => !o); setNudge(false); }} aria-expanded={open} aria-label={WHATSAPP.botao}
                className="inline-flex h-14 w-14 items-center justify-center rounded-full bg-navy text-cream shadow-paper transition-transform hover:scale-[1.03]">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.86 9.86 0 0 0 4.74 1.21h.01c5.46 0 9.9-4.45 9.9-9.91A9.85 9.85 0 0 0 12.04 2Zm5.8 14.06c-.24.68-1.41 1.3-1.95 1.38-.5.07-1.13.1-1.82-.12-.42-.13-.96-.31-1.65-.61-2.9-1.25-4.8-4.17-4.94-4.36-.14-.2-1.18-1.57-1.18-3s.75-2.13 1.02-2.42c.26-.29.58-.36.77-.36h.55c.18.01.42-.07.66.5.24.58.82 2.01.89 2.16.07.14.12.31.02.5-.1.2-.14.31-.29.48-.14.17-.3.38-.43.51-.14.14-.29.3-.13.59.17.29.74 1.22 1.59 1.98 1.09.97 2.01 1.27 2.3 1.42.29.14.46.12.63-.07.17-.2.72-.84.91-1.13.19-.29.38-.24.65-.14.26.1 1.68.79 1.97.93.29.15.48.22.55.34.07.12.07.7-.17 1.38Z" />
                </svg>
            </button>
        </div>
    );
}
