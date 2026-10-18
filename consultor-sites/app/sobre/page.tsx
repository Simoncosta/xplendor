import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { getSite } from "@/lib/api";
import { RODAPE, SOBRE } from "@/content/textos";
import { DevPlaceholder } from "@/components/dev-placeholder";
import { Network } from "@/components/sections/network";
import { IntroVideo } from "@/components/sections/intro-video";
import { ContactFinal } from "@/components/sections/contact-final";

export async function generateMetadata(): Promise<Metadata> {
    const site = await getSite();
    return { title: site ? `${SOBRE.titulo}: ${site.profile.public_name}` : SOBRE.titulo };
}

/** Sobre: a biografia, a fotografia, a mediação e a rede, só com o que está no perfil. */
export default async function SobrePage() {
    const site = await getSite();
    if (!site) notFound();
    const p = site.profile;
    return (
        <>
            <section className="container-site grid gap-12 pb-16 pt-14 md:grid-cols-12 md:pb-24 md:pt-24">
                <div className="md:col-span-7">
                    <h1 className="text-display max-w-[12ch]">{p.public_name}</h1>
                    <span className="accent-rule" aria-hidden="true" />
                    {p.network.label && <p className="muted mt-6">{p.network.label}</p>}
                    {p.bio_short ? <p className="text-lead mt-10 max-w-[54ch] whitespace-pre-line">{p.bio_short}</p> : <DevPlaceholder kind="biografia" className="mt-10 h-40 max-w-xl text-slate" />}
                    {p.brokerage.legal_name && (
                        <p className="muted mt-10">{SOBRE.trabalhoCom} {p.brokerage.ami ? RODAPE.ami(p.brokerage.legal_name, p.brokerage.ami) : p.brokerage.legal_name}.</p>
                    )}
                    <Link href="/vender" className="link-underline mt-8 inline-block font-semibold">{SOBRE.processo}</Link>
                </div>
                <div className="flex items-end md:col-span-5">
                    {p.about_photo_url ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img src={p.about_photo_url} alt={p.public_name} className="aspect-[4/5] w-full object-cover object-top" decoding="async" />
                    ) : <DevPlaceholder kind="fotografia" className="h-96 w-full text-slate" />}
                </div>
            </section>
            {p.intro_video && <IntroVideo youtubeId={p.intro_video.youtube_id} poster={p.hero.poster_url} />}
            <Network network={p.network} />
            <ContactFinal />
        </>
    );
}
