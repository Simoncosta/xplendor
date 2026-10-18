"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { COMO_VENDO, PROCESSO } from "@/content/textos";
import { cn } from "@/lib/cn";

export type Ad = { image: string | null; title: string };

/**
 * Como vendo a sua casa: os 8 passos numa linha (o número e uma palavra); o pormenor abre ao
 * tocar, por baixo. Ao lado, a composição do que a casa recebe: uma moldura de telemóvel com o
 * vídeo do consultor (o do topo, em loop) e um anúncio feito com o material dele.
 */
export function HowISell({ name, photo, video, poster, ad }: {
    name: string; photo: string | null; video: { mp4: string | null; webm: string | null }; poster: string | null; ad: Ad;
}) {
    return (
        <section id="como-vendo" aria-labelledby="como-vendo-titulo" className="section bg-cream-2">
            <div className="container-site grid gap-16 lg:grid-cols-12 lg:items-center">
                <div className="lg:col-span-7">
                    <h2 id="como-vendo-titulo" className="text-h2 max-w-[14ch]">{COMO_VENDO.titulo}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                    <p className="text-lead muted mt-6 max-w-[40ch]">{COMO_VENDO.frase}</p>

                    <StepsLine />
                    <Link href="/vender" className="link-underline mt-8 inline-block font-semibold">{COMO_VENDO.saberMais}</Link>
                </div>

                <div className="lg:col-span-5">
                    <Composition name={name} photo={photo} video={video} poster={poster} ad={ad} />
                </div>
            </div>
        </section>
    );
}

/** Os 8 passos numa linha (o número e uma palavra); o pormenor abre ao tocar, por baixo, e fecha ao tocar outra vez. */
export function StepsLine() {
    const [open, setOpen] = useState<number | null>(null);
    const step = open !== null ? PROCESSO.passos[open] : null;
    return (
        <>
            <ol className="relative mt-12 grid grid-cols-4 gap-y-8 sm:grid-cols-8">
                {PROCESSO.passos.map((p, i) => (
                    <li key={p.titulo} className="relative">
                        {/* A linha que liga os passos (por linha de 4 no telemóvel, uma só no computador). */}
                        <span className={cn("absolute left-1/2 top-[1.35rem] h-px w-full bg-line", (i % 4 === 3) && "max-sm:hidden", i === 7 && "hidden")} aria-hidden="true" />
                        <button type="button" aria-expanded={open === i} aria-controls="passo-pormenor" onClick={() => setOpen((o) => (o === i ? null : i))}
                            className="group relative flex w-full flex-col items-center gap-2 px-1 text-center">
                            <span className={cn("relative z-10 inline-flex h-11 w-11 items-center justify-center rounded-full border font-display text-lg tabular-nums transition-colors",
                                open === i ? "border-navy bg-navy text-cream" : "border-navy/30 bg-cream group-hover:border-navy")}>
                                {i + 1}
                            </span>
                            <span className={cn("text-[0.82rem] font-semibold leading-tight sm:text-sm", open === i ? "text-navy" : "text-slate group-hover:text-navy")}>{p.titulo}</span>
                        </button>
                    </li>
                ))}
            </ol>
            <div id="passo-pormenor" aria-live="polite">
                {step && (
                    <div key={open} className="market-in mt-8 border-t border-line pt-6">
                        <p className="font-display text-h3"><span className="sr-only">{COMO_VENDO.passo((open ?? 0) + 1)}: </span>{step.titulo}</p>
                        <p className="muted mt-2 max-w-[52ch]">{step.texto}</p>
                    </div>
                )}
            </div>
        </>
    );
}

/** A moldura de telemóvel com o vídeo (em loop, só no ecrã e com movimento permitido) e o anúncio. Decorativa: uma imagem com descrição. */
export function Composition({ name, photo, video, poster, ad }: { name: string; photo: string | null; video: { mp4: string | null; webm: string | null }; poster: string | null; ad: Ad }) {
    const box = useRef<HTMLDivElement>(null);
    const [play, setPlay] = useState(false);
    useEffect(() => {
        const el = box.current;
        if (!el || (!video.mp4 && !video.webm) || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
        const io = new IntersectionObserver(([e]) => setPlay(e.isIntersecting), { rootMargin: "120px" });
        io.observe(el);
        return () => io.disconnect();
    }, [video.mp4, video.webm]);
    const initials = name.split(/\s+/).map((p) => p[0]).slice(0, 2).join("");

    return (
        <figure className="mx-auto w-full max-w-[26rem]">
        <div ref={box} role="img" aria-label={COMO_VENDO.composicao} data-live className="relative aspect-[4/5] w-full">
            {/* O telemóvel */}
            <div className="absolute right-0 top-0 aspect-[9/19] w-[56%] overflow-hidden rounded-[2.2rem] border-[9px] border-navy bg-navy shadow-paper">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                {poster && <img src={poster} alt="" className="absolute inset-0 h-full w-full object-cover" loading="lazy" decoding="async" />}
                {play && (
                    <video className="absolute inset-0 h-full w-full object-cover" autoPlay muted loop playsInline preload="none" poster={poster ?? undefined}>
                        {video.webm && <source src={video.webm} type="video/webm" />}
                        {video.mp4 && <source src={video.mp4} type="video/mp4" />}
                    </video>
                )}
                <div className="absolute inset-x-0 top-0 flex justify-center pt-2"><span className="h-[0.9rem] w-[34%] rounded-full bg-navy" /></div>
                <div className="absolute inset-x-0 bottom-0 bg-[linear-gradient(180deg,transparent,rgba(14,30,55,0.75))] px-3 pb-4 pt-10 text-cream">
                    <div className="flex items-center gap-2">
                        <Avatar photo={photo} initials={initials} className="h-7 w-7" />
                        <span className="text-[0.72rem] font-semibold">{name}</span>
                    </div>
                    <div className="mt-3 h-[3px] overflow-hidden rounded-full bg-cream/30"><span className="short-progress block h-full w-1/3 bg-cream" /></div>
                </div>
            </div>

            {/* O anúncio */}
            <div className="absolute bottom-[4%] left-0 w-[60%] rounded-sm bg-white p-3 text-navy shadow-paper">
                <div className="flex items-center gap-2">
                    <Avatar photo={photo} initials={initials} className="h-8 w-8" />
                    <div className="leading-tight">
                        <p className="text-[0.75rem] font-semibold">{name}</p>
                        <p className="text-[0.68rem] text-slate">{COMO_VENDO.anuncioPatrocinado}</p>
                    </div>
                </div>
                <div className="mt-2 aspect-[4/3] overflow-hidden bg-cream-2">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    {ad.image && <img src={ad.image} alt="" className="slow-zoom h-full w-full object-cover" loading="lazy" decoding="async" />}
                </div>
                <div className="mt-2 flex items-center justify-between gap-2">
                    <p className="line-clamp-1 text-[0.8rem] font-semibold">{ad.title}</p>
                    <span className="shrink-0 bg-amber px-2 py-1 text-[0.68rem] font-semibold">{COMO_VENDO.anuncioBotao}</span>
                </div>
            </div>
        </div>
        <figcaption aria-hidden="true" className="mt-6 flex flex-wrap justify-center gap-x-8 gap-y-2 text-sm font-semibold">
            <span className="inline-flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-navy" />{COMO_VENDO.legendas.video}</span>
            <span className="inline-flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-amber" />{COMO_VENDO.legendas.anuncio}</span>
        </figcaption>
        </figure>
    );
}

function Avatar({ photo, initials, className }: { photo: string | null; initials: string; className?: string }) {
    return (
        <span className={cn("inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-cream-2 text-[0.6rem] font-semibold text-navy", className)}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            {photo ? <img src={photo} alt="" className="h-full w-full object-cover object-top" loading="lazy" /> : initials}
        </span>
    );
}
