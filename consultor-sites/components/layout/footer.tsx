import Link from "next/link";
import { cn } from "@/lib/cn";
import { REDES_NOMES, RODAPE } from "@/content/textos";
import type { Profile } from "@/lib/types";
import { CookiePreferencesLink } from "@/components/consent/cookie-consent";

/**
 * O rodapé: a empresa de mediação e a licença AMI, o livro de reclamações, a resolução
 * alternativa de litígios, a privacidade, os cookies, a informação legal, as redes e
 * "Feito com XPLENDOR" (interruptor do perfil).
 */
export function Footer({ profile }: { profile: Profile }) {
    const { brokerage, legal_links: legal, socials, network } = profile;
    const socialEntries = Object.entries(socials).filter(([, url]) => !!url) as [string, string][];
    return (
        <footer className="on-dark border-t border-line-dark">
            <div className="container-site grid gap-12 py-16 md:grid-cols-12 md:py-20">
                <div className="md:col-span-5">
                    <p className="font-display text-[1.9rem] leading-tight">{profile.public_name}</p>
                    {network.label && <p className="mt-2 text-mist">{network.label}</p>}
                    {network.logo_url && (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img src={network.logo_url} alt={network.label ?? ""} className="mt-6 h-8 w-auto opacity-90" loading="lazy" />
                    )}
                </div>
                <div className="md:col-span-4">
                    {brokerage.legal_name && (
                        <>
                            <h2 className="font-sans text-sm font-semibold text-mist">{RODAPE.mediacao}</h2>
                            <p className="mt-3">{brokerage.ami ? RODAPE.ami(brokerage.legal_name, brokerage.ami) : brokerage.legal_name}</p>
                        </>
                    )}
                    <ul className={cn("space-y-2 text-[0.95rem]", brokerage.legal_name && "mt-6")}>
                        {legal.complaints_book_url && <li><a href={legal.complaints_book_url} className="link-underline" target="_blank" rel="noopener noreferrer">{RODAPE.livro}</a></li>}
                        <li><Link href="/informacao-legal#resolucao-de-litigios" className="link-underline">{RODAPE.ral}</Link></li>
                        <li><Link href="/privacidade" className="link-underline">{RODAPE.privacidade}</Link></li>
                        <li><Link href="/cookies" className="link-underline">{RODAPE.cookies}</Link></li>
                        <li><Link href="/informacao-legal" className="link-underline">{RODAPE.legal}</Link></li>
                        <li><CookiePreferencesLink className="link-underline" /></li>
                    </ul>
                </div>
                {socialEntries.length > 0 && (
                    <div className="md:col-span-3">
                        <h2 className="font-sans text-sm font-semibold text-mist">{RODAPE.redes}</h2>
                        <ul className="mt-3 space-y-2">
                            {socialEntries.map(([k, url]) => (
                                <li key={k}><a href={url} className="link-underline" target="_blank" rel="noopener noreferrer me">{REDES_NOMES[k] ?? k}</a></li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
            {profile.show_made_with && (
                <div className="container-site border-t border-line-dark py-6 text-sm text-mist">
                    <a href="https://xplendor.tech" className="link-underline" target="_blank" rel="noopener">{RODAPE.feitoCom}</a>
                </div>
            )}
        </footer>
    );
}
