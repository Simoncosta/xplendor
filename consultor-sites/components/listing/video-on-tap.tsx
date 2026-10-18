"use client";

import { useState } from "react";
import { FICHA } from "@/content/textos";
import { track } from "@/lib/track";
import type { Image } from "@/lib/types";

/**
 * O vídeo do imóvel ou do empreendimento: a capa é a fotografia do anúncio (nada vem do YouTube
 * antes do toque); ao tocar, o vídeo pelo domínio sem cookies (youtube-nocookie.com).
 */
export function VideoOnTap({ youtubeId, cover, title, subject, slug, vertical = false }: {
    youtubeId: string; cover: Image | null; title: string; subject: "property" | "development"; slug: string; vertical?: boolean;
}) {
    const [play, setPlay] = useState(false);
    return (
        <div className={`relative overflow-hidden bg-navy ${vertical ? "aspect-[9/16]" : "aspect-video"}`}>
            {play ? (
                <iframe className="absolute inset-0 h-full w-full" src={`https://www.youtube-nocookie.com/embed/${youtubeId}?autoplay=1&rel=0&playsinline=1`} title={`${FICHA.video}: ${title}`}
                    allow="autoplay; encrypted-media; picture-in-picture" allowFullScreen referrerPolicy="strict-origin-when-cross-origin" />
            ) : (
                <button type="button" className="group absolute inset-0 flex items-center justify-center text-cream" onClick={() => { setPlay(true); track("video_open", subject, slug); }}>
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    {cover && <img src={cover.src} srcSet={cover.srcset} sizes="(min-width: 768px) 60vw, 100vw" alt="" className="absolute inset-0 h-full w-full object-cover opacity-70" loading="lazy" />}
                    <span className="relative inline-flex items-center gap-4 font-semibold">
                        <span className="inline-flex h-16 w-16 items-center justify-center rounded-full bg-amber text-navy transition-transform group-hover:scale-105">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4v16l13-8z" /></svg>
                        </span>
                        {FICHA.verVideo}
                    </span>
                </button>
            )}
        </div>
    );
}
