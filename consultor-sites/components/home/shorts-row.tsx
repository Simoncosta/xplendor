"use client";

import { useRef, useState } from "react";
import { VIDEOS_DESTAQUE } from "@/content/textos";
import { track } from "@/lib/track";

/**
 * Os meus vídeos: uma fila horizontal de Shorts (9:16), a deslizar com encaixe. Primeiro a capa
 * (servida pelo próprio site); o vídeo, sem cookies (youtube-nocookie.com), só ao tocar, e um de
 * cada vez. No computador, as setas deslizam a fila.
 */
export function ShortsRow({ videos }: { videos: { youtube_id: string }[] }) {
    const row = useRef<HTMLUListElement>(null);
    const [playing, setPlaying] = useState<string | null>(null);
    const slide = (dir: 1 | -1) => row.current?.scrollBy({ left: dir * row.current.clientWidth * 0.8, behavior: "smooth" });

    return (
        <section aria-labelledby="videos-titulo" className="overflow-hidden py-[clamp(4rem,3rem+5vw,7rem)]">
            <div className="container-site flex items-end justify-between gap-6">
                <div>
                    <h2 id="videos-titulo" className="text-h2">{VIDEOS_DESTAQUE.titulo}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                    <p className="text-lead muted mt-6 max-w-[40ch]">{VIDEOS_DESTAQUE.frase}</p>
                </div>
                {videos.length > 3 && (
                    <div className="hidden gap-2 md:flex">
                        <button type="button" onClick={() => slide(-1)} aria-label={VIDEOS_DESTAQUE.anterior} className="inline-flex h-12 w-12 items-center justify-center border border-line transition-colors hover:bg-navy hover:text-cream">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5l-7 7 7 7" stroke="currentColor" strokeWidth="1.8" /></svg>
                        </button>
                        <button type="button" onClick={() => slide(1)} aria-label={VIDEOS_DESTAQUE.seguinte} className="inline-flex h-12 w-12 items-center justify-center border border-line transition-colors hover:bg-navy hover:text-cream">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 5l7 7-7 7" stroke="currentColor" strokeWidth="1.8" /></svg>
                        </button>
                    </div>
                )}
            </div>
            <ul ref={row} className="row-gutter no-scrollbar mt-10 flex snap-x snap-mandatory gap-4 overflow-x-auto pb-2 md:gap-6">
                {videos.map((v, i) => (
                    <li key={v.youtube_id} data-reveal style={{ transitionDelay: `${Math.min(i, 4) * 60}ms` }}
                        className="relative aspect-[9/16] w-[62vw] max-w-[17rem] shrink-0 snap-start overflow-hidden bg-navy sm:w-[15rem]">
                        {playing === v.youtube_id ? (
                            <iframe className="absolute inset-0 h-full w-full" src={`https://www.youtube-nocookie.com/embed/${v.youtube_id}?autoplay=1&rel=0&playsinline=1`}
                                title={VIDEOS_DESTAQUE.iframeTitulo(i + 1)} allow="autoplay; encrypted-media; picture-in-picture" allowFullScreen referrerPolicy="strict-origin-when-cross-origin" />
                        ) : (
                            <button type="button" className="group absolute inset-0 text-cream" aria-label={VIDEOS_DESTAQUE.ver(i + 1)}
                                onClick={() => { setPlaying(v.youtube_id); track("video_open", "site"); }}>
                                {/* eslint-disable-next-line @next/next/no-img-element */}
                                <img src={`/media/yt/${v.youtube_id}`} alt="" loading="lazy" decoding="async"
                                    className="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-[1.03]"
                                    onError={(e) => { e.currentTarget.style.display = "none"; }} />
                                <span className="absolute inset-x-0 bottom-0 h-1/3 bg-[linear-gradient(180deg,transparent,rgba(14,30,55,0.7))]" aria-hidden="true" />
                                <span className="absolute bottom-4 left-4 inline-flex h-12 w-12 items-center justify-center rounded-full bg-amber text-navy transition-transform group-hover:scale-105" aria-hidden="true">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 4v16l13-8z" /></svg>
                                </span>
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
