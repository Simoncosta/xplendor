import { REDE } from "@/content/textos";
import { networkName } from "@/lib/format";
import type { Profile } from "@/lib/types";
import { SectionTitle } from "@/components/ui/section-title";

/** Porque a rede: o texto e o logótipo vêm do perfil (fornecidos pela agência). Sem texto, não aparece. */
export function Network({ network }: { network: Profile["network"] }) {
    const name = networkName(network.label);
    if (!network.text || !name) return null;
    return (
        <section aria-labelledby="rede-titulo" className="section">
            <div className="container-site grid gap-10 md:grid-cols-12">
                <div className="md:col-span-5">
                    <SectionTitle id="rede-titulo">{REDE.titulo(name)}</SectionTitle>
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    {network.logo_url && <img src={network.logo_url} alt={name} className="mt-10 h-10 w-auto" loading="lazy" />}
                </div>
                <div className="text-lead space-y-5 whitespace-pre-line md:col-span-6 md:col-start-7 md:mt-3">{network.text}</div>
            </div>
        </section>
    );
}
