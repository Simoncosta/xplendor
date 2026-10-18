import Link from "next/link";
import { HERO } from "@/content/textos";
import type { Profile } from "@/lib/types";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";
import { DevPlaceholder } from "@/components/dev-placeholder";
import { HeroMedia } from "./hero-media";

/**
 * O topo da página inicial, a ocupar o ecrã: o vídeo de fundo (ou a capa), a camada em azul
 * escuro para a leitura, a promessa numa frase e os dois botões. A fotografia recortada do
 * consultor fica à frente, assente na base (à direita; no telemóvel, por baixo do texto).
 */
export function Hero({ profile, hasProperties, howOnPage = true }: { profile: Profile; hasProperties: boolean; howOnPage?: boolean }) {
    const { hero, photo_url: photo } = profile;
    const promise = hero.promise ?? HERO.promessaPorOmissao;
    const hasMedia = !!(hero.poster_url || hero.video_mp4_url || hero.video_webm_url);

    return (
        <section aria-labelledby="topo-titulo" className="on-dark relative isolate overflow-hidden">
            <HeroMedia poster={hero.poster_url} poster960={hero.poster_960_url} mp4={hero.video_mp4_url} webm={hero.video_webm_url} />
            {/* A camada para a leitura: mais densa à esquerda (o texto) e em baixo (no telemóvel). */}
            <div className="absolute inset-0 bg-[linear-gradient(180deg,rgba(14,30,55,0.55)_0%,rgba(14,30,55,0.35)_35%,rgba(14,30,55,0.92)_100%)] md:bg-[linear-gradient(90deg,var(--color-navy)_0%,rgba(14,30,55,0.88)_38%,rgba(14,30,55,0.35)_70%,rgba(14,30,55,0.15)_100%)]" aria-hidden="true" />
            {!hasMedia && <DevPlaceholder kind="video" className="absolute bottom-4 left-4 z-10 hidden px-3 py-2 text-mist md:right-10 md:left-auto md:top-[calc(var(--top-offset)+1.5rem)] md:bottom-auto md:grid" />}

            <div className="container-site relative grid min-h-[100svh] grid-rows-[1fr_auto] md:grid-cols-12 md:grid-rows-1">
                <div className="flex flex-col justify-end pb-10 pt-[calc(var(--top-offset)+3.5rem)] md:col-span-7 md:justify-center md:pb-24 md:pt-[calc(var(--top-offset)+4rem)]">
                    <h1 id="topo-titulo" className="text-display max-w-[13ch] md:max-w-[14ch]">{promise}</h1>
                    <div className="mt-10 flex flex-col gap-3 sm:flex-row">
                        <Link href="/contacto?pedido=estudo-da-zona" className={cn(buttonVariants({ variant: "primary", size: "lg" }))}>{HERO.botaoPrincipal}</Link>
                        {hasProperties
                            ? <Link href="/imoveis" className={cn(buttonVariants({ variant: "outlineDark", size: "lg" }))}>{HERO.botaoSecundario}</Link>
                            : <Link href={howOnPage ? "#como-vendo" : "/vender"} className={cn(buttonVariants({ variant: "outlineDark", size: "lg" }))}>{HERO.botaoComoVendo}</Link>}
                    </div>
                </div>
                <div className="relative flex items-end justify-center md:col-span-5 md:justify-end">
                    {photo ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img src={photo} alt={profile.public_name} className="relative h-[46svh] w-auto max-w-full object-contain object-bottom md:absolute md:bottom-0 md:right-0 md:h-[86%] md:max-h-[52rem]" fetchPriority="high" decoding="async" />
                    ) : <DevPlaceholder kind="fotografia" className="mb-0 h-[38svh] w-[70%] max-w-xs text-mist md:absolute md:bottom-0 md:right-0 md:h-[70%] md:w-[24rem] md:max-w-none" />}
                </div>
            </div>
        </section>
    );
}
