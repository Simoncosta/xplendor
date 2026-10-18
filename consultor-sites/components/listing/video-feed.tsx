"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { VIDEOS } from "@/content/textos";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";
import { track } from "@/lib/track";
import type { Image } from "@/lib/types";
import { DraftBadge } from "./badges";

export type FeedItem = { key: string; youtubeId: string; cover: Image | null; title: string; line: string; href: string; subject: "property" | "development"; slug: string; draft: boolean };

/**
 * O feed vertical (estilo Reels): um vídeo de cada vez, com encaixe ao deslizar, e as setas do
 * teclado. Primeiro a capa (a fotografia do anúncio); o vídeo sem cookies só ao toque. Por cima,
 * "Ver o imóvel" e "Quero visitar".
 */
export function VideoFeed({ items }: { items: FeedItem[] }) {
    const feed = useRef<HTMLDivElement>(null);
    const [playing, setPlaying] = useState<string | null>(null);
    const [current, setCurrent] = useState(0);

    useEffect(() => {
        const el = feed.current;
        if (!el) return;
        const io = new IntersectionObserver((entries) => {
            for (const e of entries) {
                if (!e.isIntersecting) continue;
                const idx = Number((e.target as HTMLElement).dataset.index);
                setCurrent(idx);
                setPlaying((p) => (p && items[idx]?.key !== p ? null : p)); // ao mudar de vídeo, o anterior para (volta à capa)
            }
        }, { root: el, threshold: 0.6 });
        el.querySelectorAll("[data-index]").forEach((s) => io.observe(s));
        return () => io.disconnect();
    }, [items]);

    const go = (i: number) => feed.current?.querySelector(`[data-index="${Math.max(0, Math.min(items.length - 1, i))}"]`)?.scrollIntoView({ behavior: "smooth" });

    if (items.length === 0) {
        return <section className="container-site py-24"><h1 className="text-h2">{VIDEOS.titulo}</h1><p className="text-lead muted mt-6">{VIDEOS.vazio}</p></section>;
    }
    return (
        <section aria-label={VIDEOS.titulo} className="on-dark">
            <h1 className="sr-only">{VIDEOS.titulo}</h1>
            <p className="sr-only">{VIDEOS.ajuda}</p>
            <div ref={feed} tabIndex={0} className="h-[calc(100svh-var(--top-offset))] snap-y snap-mandatory overflow-y-auto overscroll-contain outline-none [scrollbar-width:none]"
                onKeyDown={(e) => {
                    if (e.key === "ArrowDown" || e.key === "j") { e.preventDefault(); go(current + 1); }
                    if (e.key === "ArrowUp" || e.key === "k") { e.preventDefault(); go(current - 1); }
                }}>
                {items.map((it, i) => (
                    <article key={it.key} data-index={i} aria-label={`${i + 1} / ${items.length}: ${it.title}`} className="relative flex h-full snap-start snap-always items-center justify-center">
                        <div className="relative h-full w-full max-w-[min(100vw,calc((100svh-var(--top-offset))*9/16))] overflow-hidden bg-navy">
                            {playing === it.key ? (
                                <iframe className="absolute inset-0 h-full w-full" src={`https://www.youtube-nocookie.com/embed/${it.youtubeId}?autoplay=1&rel=0&playsinline=1`}
                                    title={it.title} allow="autoplay; encrypted-media; picture-in-picture" allowFullScreen referrerPolicy="strict-origin-when-cross-origin" />
                            ) : (
                                <button type="button" className="absolute inset-0 text-cream" aria-label={`${VIDEOS.tocar}: ${it.title}`}
                                    onClick={() => { setPlaying(it.key); track("video_open", it.subject, it.slug); }}>
                                    {/* eslint-disable-next-line @next/next/no-img-element */}
                                    {it.cover && <img src={it.cover.src} srcSet={it.cover.srcset} sizes="(min-width: 768px) 40vw, 100vw" alt="" className="absolute inset-0 h-full w-full object-cover" loading={i < 2 ? "eager" : "lazy"} />}
                                    <span className="absolute inset-0 bg-[linear-gradient(180deg,rgba(14,30,55,0.15)_40%,rgba(14,30,55,0.9)_100%)]" />
                                    <span className="absolute left-1/2 top-1/2 inline-flex h-16 w-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-amber text-navy">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4v16l13-8z" /></svg>
                                    </span>
                                </button>
                            )}
                            {playing !== it.key && (
                                <div className="pointer-events-none absolute inset-x-0 bottom-0 p-5 pb-8">
                                    {it.draft && <DraftBadge className="mb-3" />}
                                    <h2 className="font-display text-[1.6rem] leading-tight">{it.title}</h2>
                                    <p className="mt-1 text-sm text-mist">{it.line}</p>
                                    <div className="pointer-events-auto mt-4 flex gap-2">
                                        <Link href={it.href} className={cn(buttonVariants({ variant: "outlineDark", size: "sm" }))}>{it.subject === "property" ? VIDEOS.verImovel : VIDEOS.verEmpreendimento}</Link>
                                        <Link href={`${it.href}#visitar`} className={cn(buttonVariants({ variant: "primary", size: "sm" }))}>{VIDEOS.quero}</Link>
                                    </div>
                                </div>
                            )}
                        </div>
                    </article>
                ))}
            </div>
            <div className="pointer-events-none fixed right-4 top-1/2 z-30 hidden -translate-y-1/2 flex-col gap-2 md:flex">
                <button type="button" onClick={() => go(current - 1)} disabled={current === 0} aria-label={VIDEOS.anterior} className="pointer-events-auto inline-flex h-11 w-11 items-center justify-center bg-cream text-navy disabled:opacity-40">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 15l7-7 7 7" stroke="currentColor" strokeWidth="1.8" /></svg>
                </button>
                <button type="button" onClick={() => go(current + 1)} disabled={current === items.length - 1} aria-label={VIDEOS.seguinte} className="pointer-events-auto inline-flex h-11 w-11 items-center justify-center bg-cream text-navy disabled:opacity-40">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 9l7 7 7-7" stroke="currentColor" strokeWidth="1.8" /></svg>
                </button>
            </div>
        </section>
    );
}
