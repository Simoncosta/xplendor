import type { Metadata, Viewport } from "next";
import type { CSSProperties, ReactNode } from "react";
import "./globals.css";
import { cookies } from "next/headers";
import { CONSENT_BOOTSTRAP, CONSENT_COOKIE, parseConsent } from "@/lib/consent";
import { fraunces, manrope } from "@/lib/fonts";
import { getSite } from "@/lib/api";
import { isDev } from "@/lib/env";
import { HERO, NAV } from "@/content/textos";
import { MotionProvider, REVEAL_BOOTSTRAP, RevealObserver } from "@/components/motion";
import { Header, type NavItem } from "@/components/layout/header";
import { Footer } from "@/components/layout/footer";
import { PreviewBanner } from "@/components/layout/preview-banner";
import { WhatsApp } from "@/components/whatsapp";
import { CookieConsent } from "@/components/consent/cookie-consent";
import { OriginTracker } from "@/components/origin-tracker";

export const viewport: Viewport = { themeColor: "#0E1E37", width: "device-width", initialScale: 1 };

export async function generateMetadata(): Promise<Metadata> {
    const site = await getSite();
    if (!site) return { title: "Página não encontrada", robots: { index: false, follow: false } };
    const p = site.profile;
    // Só se indexa um site publicado, fora da pré-visualização e fora de dev.
    const index = p.site_status === "publicado" && !site.preview && !isDev;
    const canonicalHost = p.primary_host ?? site.host;
    return {
        metadataBase: new URL(`https://${canonicalHost}`),
        title: { default: p.network.label ? `${p.public_name}, ${p.network.label}` : p.public_name, template: `%s | ${p.public_name}` },
        description: p.hero.promise ?? HERO.promessaPorOmissao,
        robots: index ? { index: true, follow: true } : { index: false, follow: false },
        openGraph: { siteName: p.public_name, locale: "pt_PT", type: "website", ...(p.share_image_url ? { images: [{ url: p.share_image_url, width: 1200, height: 630 }] } : {}) },
        twitter: { card: p.share_image_url ? "summary_large_image" : "summary" },
        formatDetection: { telephone: false },
    };
}

/** As páginas que já existem; Comprar e Blog entram no menu quando existirem (F4 e F5). */
function navItems(): NavItem[] {
    return [
        { href: "/vender", label: NAV.vender },
        { href: "/imoveis", label: NAV.imoveis },
        { href: "/empreendimentos", label: NAV.empreendimentos },
        { href: "/sobre", label: NAV.sobre },
        { href: "/contacto", label: NAV.contacto },
    ];
}

export default async function RootLayout({ children }: { children: ReactNode }) {
    const site = await getSite();
    const fonts = `${fraunces.variable} ${manrope.variable}`;
    if (!site) {
        return <html lang="pt-PT" className={fonts}><body>{children}</body></html>;
    }
    const p = site.profile;
    const brand = { "--brand-navy": p.colors.navy, "--brand-blue": p.colors.blue, "--brand-amber": p.colors.amber, "--brand-cream": p.colors.cream, "--brand-slate": p.colors.slate } as CSSProperties;
    const measure = !site.preview; // sem medição nem consentimento na pré-visualização
    const policyVersion = p.privacy_policy_version ?? "sem-versao";
    const saved = parseConsent((await cookies()).get(CONSENT_COOKIE)?.value);
    const initialConsent = saved && saved.v === policyVersion ? saved : null;

    return (
        <html lang="pt-PT" className={`${fonts}${site.preview ? " has-preview" : ""}`} style={brand} suppressHydrationWarning>
            <head>
                <script dangerouslySetInnerHTML={{ __html: REVEAL_BOOTSTRAP }} />
                {measure && <script dangerouslySetInnerHTML={{ __html: CONSENT_BOOTSTRAP }} />}
            </head>
            <body className="min-h-screen bg-cream">
                <a href="#conteudo" className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:bg-amber focus:px-4 focus:py-2 focus:text-navy">{NAV.saltar}</a>
                <MotionProvider>
                    <Header name={p.public_name} signatureUrl={p.signature_url} items={navItems()} cta={{ href: "/contacto?pedido=estudo-da-zona", label: NAV.cta }}
                        banner={site.preview ? <PreviewBanner /> : undefined} />
                    <main id="conteudo">{children}</main>
                    <Footer profile={p} />
                    {p.whatsapp ? <WhatsApp number={p.whatsapp.number} name={p.public_name} photoUrl={p.photo_url} messages={p.whatsapp.messages} />
                        : process.env.NODE_ENV !== "production" ? <WhatsApp number={null} name={p.public_name} photoUrl={p.photo_url} messages={{
                            // Só dev, sem número no perfil: as mensagens por omissão da XPLENDOR, para ver o popup.
                            general: { popup: "Está a pensar vender? Diga-me só a zona da sua casa e respondo com o que o mercado está a mostrar. Sem compromisso.", prefill: "" },
                            property: { popup: "Há pormenores que as fotografias não mostram. Pergunte-me o que quiser sobre este imóvel.", prefill: "" },
                            development: { popup: "Quer saber que frações ainda estão disponíveis e como funciona o plano de pagamentos? Pergunte-me.", prefill: "" },
                            buy: { popup: "Diga-me o que procura e aviso-o quando entrar algo assim.", prefill: "" },
                        }} /> : null}
                    {(measure || process.env.NODE_ENV !== "production") && (
                        <CookieConsent policyVersion={policyVersion} initial={initialConsent} hasStatistics={!!p.measurement.ga4_id} hasMarketing={!!p.measurement.meta_pixel_id}
                            devNoIds={process.env.NODE_ENV !== "production" && !p.measurement.ga4_id && !p.measurement.meta_pixel_id} />
                    )}
                    {measure && <OriginTracker />}
                    <RevealObserver />
                </MotionProvider>
            </body>
        </html>
    );
}
