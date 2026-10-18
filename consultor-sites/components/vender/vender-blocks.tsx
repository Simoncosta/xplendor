import Link from "next/link";
import { ESTUDO, VENDER } from "@/content/textos";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";
import { Signature } from "@/components/signature";
import { Composition, StepsLine, type Ad } from "@/components/home/how-i-sell";
import type { Profile } from "@/lib/types";

/** O início da página Vender: o título, uma frase, o botão e uma fotografia do consultor (a vista da zona), com zoom lento. */
export function VenderHero({ image, image960 }: { image: string | null; image960?: string | null }) {
    return (
        <section className="container-site grid items-end gap-10 pb-14 pt-14 md:grid-cols-12 md:pb-20 md:pt-24">
            <div className="md:col-span-6">
                <h1 className="text-display max-w-[12ch]">{VENDER.titulo}</h1>
                <span className="accent-rule" aria-hidden="true" />
                <p className="text-lead muted mt-8 max-w-[40ch]">{VENDER.lead}</p>
                <a href="#contacto" className={cn(buttonVariants({ variant: "primary", size: "lg" }), "mt-9 w-full sm:w-auto")}>{VENDER.botao}</a>
            </div>
            {image && (
                <div className="overflow-hidden md:col-span-6">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={image} srcSet={image960 ? `${image960} 960w, ${image} 1920w` : undefined} sizes="(min-width: 768px) 50vw, 100vw" alt=""
                        className="slow-zoom aspect-[4/3] w-full object-cover" fetchPriority="high" decoding="async" />
                </div>
            )}
        </section>
    );
}

/** O que faço pela sua casa: uma frase, o plano em cinco palavras e a composição (telemóvel com o vídeo e o anúncio). */
export function WhatIDo({ name, photo, video, poster, ad }: { name: string; photo: string | null; video: { mp4: string | null; webm: string | null }; poster: string | null; ad: Ad }) {
    return (
        <section aria-labelledby="o-que-faco-titulo" className="section bg-cream-2">
            <div className="container-site grid gap-14 lg:grid-cols-12 lg:items-center">
                <div className="lg:col-span-6">
                    <h2 id="o-que-faco-titulo" className="text-h2 max-w-[14ch]">{VENDER.oQueFaco.titulo}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                    <p className="text-lead muted mt-6 max-w-[40ch]">{VENDER.oQueFaco.frase}</p>
                    <ul className="mt-10 flex flex-wrap gap-2">
                        {VENDER.oQueFaco.itens.map((it) => <li key={it} className="border border-line bg-cream px-4 py-2 text-sm font-semibold">{it}</li>)}
                    </ul>
                </div>
                <div className="lg:col-span-5 lg:col-start-8">
                    <Composition name={name} photo={photo} video={video} poster={poster} ad={ad} />
                </div>
            </div>
        </section>
    );
}

/** Do primeiro contacto à escritura: os 8 passos com o componente visual da página inicial. */
export function VenderSteps() {
    return (
        <section aria-labelledby="passos-titulo" className="section">
            <div className="container-site">
                <h2 id="passos-titulo" className="text-h2 max-w-[16ch]">{VENDER.passos.titulo}</h2>
                <span className="accent-rule" aria-hidden="true" />
                <p className="text-lead muted mt-6 max-w-[44ch]">{VENDER.passos.frase}</p>
                <div className="max-w-4xl"><StepsLine /></div>
            </div>
        </section>
    );
}

/** Compradores qualificados: uma frase e a fotografia do consultor, com a assinatura. */
export function BuyersShort({ hasCreditPartner, profile }: { hasCreditPartner: boolean; profile: Profile }) {
    return (
        <section aria-labelledby="compradores-titulo" className="section">
            <div className="container-site grid items-center gap-12 md:grid-cols-12">
                {profile.about_photo_url && (
                    <div data-reveal className="overflow-hidden md:col-span-5">
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img src={profile.about_photo_url} alt={profile.public_name} className="slow-zoom aspect-[4/5] w-full object-cover object-top" loading="lazy" decoding="async" />
                    </div>
                )}
                <div className={profile.about_photo_url ? "md:col-span-6 md:col-start-7" : "md:col-span-8"}>
                    <h2 id="compradores-titulo" className="text-h2 max-w-[16ch]">{VENDER.compradores.titulo}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                    <p className="text-lead muted mt-6 max-w-[42ch]">{hasCreditPartner ? VENDER.compradores.fraseComParceiro : VENDER.compradores.fraseSemParceiro}</p>
                    {profile.signature_url && <Signature alt="" draw className="mt-10 h-14 md:h-16" />}
                </div>
            </div>
        </section>
    );
}

/** O estudo da zona sem valores do INE nas freguesias de atuação: o título, uma frase e o botão. */
export function StudyShort() {
    return (
        <section aria-labelledby="estudo-titulo" className="section on-dark">
            <div className="container-site flex flex-col items-start gap-8 md:flex-row md:items-end md:justify-between">
                <div>
                    <h2 id="estudo-titulo" className="text-h2 max-w-[14ch]">{ESTUDO.titulo}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                    <p className="text-lead muted mt-6 max-w-[44ch]">{VENDER.estudo.frase}</p>
                </div>
                <Link href="/contacto?pedido=estudo-da-zona" className={cn(buttonVariants({ variant: "primary", size: "lg" }), "w-full sm:w-auto")}>{ESTUDO.botao}</Link>
            </div>
        </section>
    );
}

