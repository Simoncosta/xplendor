import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getSite } from "@/lib/api";
import { isDev } from "@/lib/env";
import { COOKIES, COOKIES_PAGINA } from "@/content/textos";
import { PageIntro } from "@/components/page-intro";
import { CookiePreferencesLink } from "@/components/consent/cookie-consent";

export const metadata: Metadata = { title: COOKIES_PAGINA.titulo, description: COOKIES_PAGINA.intro };

/** A política de cookies: só os cookies que este site usa de facto (o GA4 e o Pixel só se o perfil os tiver). */
export default async function CookiesPage() {
    const site = await getSite();
    if (!site) notFound();
    const m = site.profile.measurement;
    const L = COOKIES_PAGINA.linhas;
    const rows = [L.consentimento, L.origem, L.previsualizacao, ...(m.ga4_id || isDev ? [L.ga] : []), ...(m.meta_pixel_id || isDev ? [L.pixel] : [])];
    const C = COOKIES_PAGINA.colunas;
    return (
        <>
            <PageIntro title={COOKIES_PAGINA.titulo} lead={COOKIES_PAGINA.intro} />
            <div className="container-site pb-24">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[40rem] border-collapse text-left">
                        <thead><tr className="border-b border-navy">
                            {[C.nome, C.finalidade, C.duracao, C.categoria].map((h) => <th key={h} scope="col" className="pb-3 pr-6 text-sm font-semibold">{h}</th>)}
                        </tr></thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.nome} className="border-b border-line align-top">
                                    <td className="py-5 pr-6 font-mono text-sm">{r.nome}</td>
                                    <td className="py-5 pr-6">{r.finalidade}</td>
                                    <td className="muted py-5 pr-6">{r.duracao}</td>
                                    <td className="muted py-5">{r.categoria}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="mt-10">{COOKIES_PAGINA.mudar} <CookiePreferencesLink className="link-underline font-semibold" label={COOKIES.preferencias} /></p>
            </div>
        </>
    );
}
