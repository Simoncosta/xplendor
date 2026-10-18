"use client";

import Link from "next/link";
import { CONTACTO_CURTO } from "@/content/textos";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";
import { track } from "@/lib/track";
import type { ZonePhoto } from "@/lib/types";

/** O contacto curto: o WhatsApp em destaque (quando há número) e a mensagem pelo formulário. */
export function ContactShort({ whatsapp, firstName, prefill, photo }: { whatsapp: string | null; firstName: string; prefill: string; photo: ZonePhoto | null }) {
    const href = whatsapp ? `https://wa.me/${whatsapp.replace(/[^0-9]/g, "")}?text=${encodeURIComponent(prefill.replaceAll("{nome}", firstName))}` : null;
    return (
        <section id="contacto" aria-labelledby="contacto-titulo" className="on-dark relative isolate overflow-hidden">
            {photo && (
                <div className="absolute inset-0 -z-10" aria-hidden="true">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={photo.src} srcSet={`${photo.src_960} 960w, ${photo.src} 2400w`} sizes="100vw" alt="" loading="lazy" decoding="async" className="slow-zoom h-full w-full object-cover" />
                    <div className="absolute inset-0 bg-navy/80" />
                </div>
            )}
            <div className="container-site section flex flex-col items-start gap-10 md:flex-row md:items-end md:justify-between">
                <div>
                    <h2 id="contacto-titulo" className="text-h2 max-w-[14ch]">{CONTACTO_CURTO.titulo}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                    <p className="text-lead muted mt-6 max-w-[34ch]">{CONTACTO_CURTO.frase}</p>
                </div>
                <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                    {href && (
                        <a href={href} target="_blank" rel="noopener noreferrer" onClick={() => track("whatsapp_click", "site")} className={cn(buttonVariants({ variant: "primary", size: "lg" }), "gap-3")}>
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.86 9.86 0 0 0 4.74 1.21h.01c5.46 0 9.9-4.45 9.9-9.91A9.85 9.85 0 0 0 12.04 2Zm5.8 14.06c-.24.68-1.41 1.3-1.95 1.38-.5.07-1.13.1-1.82-.12-.42-.13-.96-.31-1.65-.61-2.9-1.25-4.8-4.17-4.94-4.36-.14-.2-1.18-1.57-1.18-3s.75-2.13 1.02-2.42c.26-.29.58-.36.77-.36h.55c.18.01.42-.07.66.5.24.58.82 2.01.89 2.16.07.14.12.31.02.5-.1.2-.14.31-.29.48-.14.17-.3.38-.43.51-.14.14-.29.3-.13.59.17.29.74 1.22 1.59 1.98 1.09.97 2.01 1.27 2.3 1.42.29.14.46.12.63-.07.17-.2.72-.84.91-1.13.19-.29.38-.24.65-.14.26.1 1.68.79 1.97.93.29.15.48.22.55.34.07.12.07.7-.17 1.38Z" /></svg>
                            {CONTACTO_CURTO.whatsapp}
                        </a>
                    )}
                    <Link href="/contacto" className={cn(buttonVariants({ variant: href ? "outlineDark" : "primary", size: "lg" }))}>{CONTACTO_CURTO.mensagem}</Link>
                </div>
            </div>
        </section>
    );
}
