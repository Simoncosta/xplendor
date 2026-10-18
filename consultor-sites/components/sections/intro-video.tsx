"use client";

import { useState } from "react";
import { VIDEO_APRESENTACAO } from "@/content/textos";

/**
 * O vídeo de apresentação: uma capa própria (nada vem do YouTube antes do toque) e o vídeo pelo
 * domínio sem cookies (youtube-nocookie.com), carregado só ao toque.
 */
export function IntroVideo({ youtubeId, poster }: { youtubeId: string; poster: string | null }) {
    const [play, setPlay] = useState(false);
    return (
        <section aria-labelledby="video-titulo" className="section">
            <div className="container-site grid items-end gap-10 md:grid-cols-12">
                <div className="md:col-span-4">
                    <h2 id="video-titulo" className="text-h2">{VIDEO_APRESENTACAO.titulo}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                    <p className="text-lead muted mt-6">{VIDEO_APRESENTACAO.texto}</p>
                </div>
                <div className="relative aspect-video overflow-hidden bg-navy md:col-span-8">
                    {play ? (
                        <iframe className="absolute inset-0 h-full w-full" src={`https://www.youtube-nocookie.com/embed/${youtubeId}?autoplay=1&rel=0`} title={VIDEO_APRESENTACAO.iframeTitulo}
                            allow="autoplay; encrypted-media; picture-in-picture" allowFullScreen referrerPolicy="strict-origin-when-cross-origin" />
                    ) : (
                        <button type="button" onClick={() => setPlay(true)} className="group absolute inset-0 flex items-end p-6 text-left text-cream md:p-10">
                            {/* eslint-disable-next-line @next/next/no-img-element */}
                            {poster && <img src={poster} alt="" className="absolute inset-0 h-full w-full object-cover opacity-70" loading="lazy" />}
                            <span className="relative inline-flex items-center gap-4 font-semibold">
                                <span className="inline-flex h-16 w-16 items-center justify-center rounded-full bg-amber text-navy transition-transform group-hover:scale-105">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4v16l13-8z" /></svg>
                                </span>
                                {VIDEO_APRESENTACAO.botao}
                            </span>
                        </button>
                    )}
                </div>
            </div>
        </section>
    );
}
