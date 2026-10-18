"use client";

import { useEffect, useRef, useState } from "react";

/**
 * O fundo do topo: a imagem de capa logo (é o maior elemento da página) e o vídeo em loop, sem
 * som, só depois de a página carregar. Com movimento reduzido ou poupança de dados, só a imagem.
 */
export function HeroMedia({ poster, poster960, mp4, webm }: { poster: string | null; poster960?: string | null; mp4: string | null; webm: string | null }) {
    const [play, setPlay] = useState(false);
    const [ready, setReady] = useState(false);
    const video = useRef<HTMLVideoElement>(null);

    useEffect(() => {
        if (!mp4 && !webm) return;
        const reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        const saveData = (navigator as Navigator & { connection?: { saveData?: boolean } }).connection?.saveData === true;
        if (reduce || saveData) return;
        const start = () => setPlay(true);
        if (document.readyState === "complete") { const t = window.setTimeout(start, 400); return () => window.clearTimeout(t); }
        window.addEventListener("load", start, { once: true });
        return () => window.removeEventListener("load", start);
    }, [mp4, webm]);

    return (
        <div className="absolute inset-0" aria-hidden="true">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            {poster && <img src={poster} srcSet={poster960 ? `${poster960} 960w, ${poster} 1920w` : undefined} sizes="100vw" alt="" className="absolute inset-0 h-full w-full object-cover" fetchPriority="high" decoding="async" />}
            {play && (
                <video ref={video} className={`absolute inset-0 h-full w-full object-cover transition-opacity duration-1000 ${ready ? "opacity-100" : "opacity-0"}`}
                    autoPlay muted loop playsInline preload="auto" poster={poster ?? undefined} onCanPlay={() => setReady(true)}>
                    {webm && <source src={webm} type="video/webm" />}
                    {mp4 && <source src={mp4} type="video/mp4" />}
                </video>
            )}
        </div>
    );
}
