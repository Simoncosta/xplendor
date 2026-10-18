"use client";

import { useRef, useState } from "react";
import { FICHA } from "@/content/textos";
import type { Image } from "@/lib/types";

/**
 * A galeria: uma fotografia de cada vez, a deslizar (dedo, setas do teclado ou botões), com o
 * contador. A primeira carrega logo; as outras quando chegam.
 */
export function Gallery({ images, title }: { images: Image[]; title: string }) {
    const track = useRef<HTMLDivElement>(null);
    const [index, setIndex] = useState(0);
    if (images.length === 0) return <div className="aspect-[4/3] w-full bg-cream-2 md:aspect-[16/9]" aria-hidden="true" />;
    const go = (i: number) => {
        const n = Math.max(0, Math.min(images.length - 1, i));
        track.current?.children[n]?.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "start" });
    };
    return (
        <section aria-roledescription="galeria" aria-label={`${FICHA.galeria}: ${title}`} className="relative">
            <div ref={track} tabIndex={0} className="flex snap-x snap-mandatory overflow-x-auto scroll-smooth [scrollbar-width:none] focus-visible:outline-offset-[-3px]"
                onKeyDown={(e) => { if (e.key === "ArrowRight") { e.preventDefault(); go(index + 1); } if (e.key === "ArrowLeft") { e.preventDefault(); go(index - 1); } }}
                onScroll={(e) => { const el = e.currentTarget; setIndex(Math.round(el.scrollLeft / el.clientWidth)); }}>
                {images.map((img, i) => (
                    <figure key={img.src} className="w-full shrink-0 snap-start" aria-label={`${i + 1} / ${images.length}`}>
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img src={img.src} srcSet={img.srcset} sizes="(min-width: 1280px) 1200px, 100vw" alt={img.alt ?? `${title}, ${FICHA.fotografia} ${i + 1}`}
                            width={img.width ?? undefined} height={img.height ?? undefined} loading={i === 0 ? "eager" : "lazy"} fetchPriority={i === 0 ? "high" : undefined}
                            decoding="async" className="aspect-[4/3] w-full object-cover md:aspect-[16/9]" />
                    </figure>
                ))}
            </div>
            {images.length > 1 && (
                <div className="absolute bottom-4 right-4 flex items-center gap-2">
                    <span className="bg-navy/85 px-3 py-1.5 text-sm text-cream tabular-nums" aria-live="polite">{index + 1} / {images.length}</span>
                    <button type="button" onClick={() => go(index - 1)} disabled={index === 0} aria-label={FICHA.anterior} className="inline-flex h-10 w-10 items-center justify-center bg-cream text-navy disabled:opacity-50">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5l-7 7 7 7" stroke="currentColor" strokeWidth="1.8" /></svg>
                    </button>
                    <button type="button" onClick={() => go(index + 1)} disabled={index === images.length - 1} aria-label={FICHA.seguinte} className="inline-flex h-10 w-10 items-center justify-center bg-cream text-navy disabled:opacity-50">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 5l7 7-7 7" stroke="currentColor" strokeWidth="1.8" /></svg>
                    </button>
                </div>
            )}
        </section>
    );
}
