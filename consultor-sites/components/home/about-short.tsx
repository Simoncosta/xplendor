import Link from "next/link";
import { QUEM_SOU } from "@/content/textos";
import type { Profile } from "@/lib/types";
import { Signature } from "@/components/signature";
import { firstSentence } from "@/lib/home";

/** Quem sou: a fotografia com fundo, a primeira frase da biografia (ou o nome e a rede) e a assinatura. */
export function AboutShort({ profile }: { profile: Profile }) {
    const photo = profile.about_photo_url;
    const bio = firstSentence(profile.bio_short);
    if (!photo && !bio) return null;
    return (
        <section aria-labelledby="quem-sou-titulo" className="section">
            <div className="container-site grid items-center gap-12 md:grid-cols-12">
                {photo && (
                    <div data-reveal className="overflow-hidden md:col-span-5">
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img src={photo} alt={profile.public_name} className="slow-zoom aspect-[4/5] w-full object-cover object-top" loading="lazy" decoding="async" />
                    </div>
                )}
                <div className={photo ? "md:col-span-6 md:col-start-7" : "md:col-span-8"}>
                    <h2 id="quem-sou-titulo" className="text-h2">{QUEM_SOU.titulo}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                    {/* A biografia já diz quem é (e onde); sem ela, o nome e a rede. */}
                    {bio ? <p className="text-lead mt-8 max-w-[44ch]">{bio}</p>
                        : <p className="text-lead mt-8 font-semibold">{[profile.public_name, profile.network.label].filter(Boolean).join(", ")}</p>}
                    {profile.signature_url && <Signature alt="" draw className="mt-10 h-16 md:h-20" />}
                    <Link href="/sobre" className="link-underline mt-8 block w-fit font-semibold">{QUEM_SOU.saberMais}</Link>
                </div>
            </div>
        </section>
    );
}
