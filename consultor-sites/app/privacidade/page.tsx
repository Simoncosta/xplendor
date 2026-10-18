import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import type { ReactNode } from "react";
import { getSite } from "@/lib/api";
import { PRIVACIDADE } from "@/content/textos";
import { Missing, PageIntro } from "@/components/page-intro";

export const metadata: Metadata = { title: PRIVACIDADE.titulo, description: PRIVACIDADE.descricao };

function Block({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="grid gap-4 border-t border-line py-10 md:grid-cols-12">
            <h2 className="font-display text-h3 md:col-span-4">{title}</h2>
            <div className="max-w-[62ch] space-y-4 md:col-span-7 md:col-start-6">{children}</div>
        </section>
    );
}

/**
 * A política de privacidade, em rascunho para validar. Os dados do responsável vêm do perfil; o
 * que falta aparece como marcador visível (um site publicado tem-nos todos).
 */
export default async function PrivacidadePage() {
    const site = await getSite();
    if (!site) notFound();
    const p = site.profile;
    const c = p.data_controller;
    const S = PRIVACIDADE.seccoes;
    const M = PRIVACIDADE.marcadores;
    const v = (val: string | null, what: string): ReactNode => (val ? val : <Missing what={what} />);
    const email = c.email ? <a href={`mailto:${c.email}`} className="link-underline">{c.email}</a> : <Missing what={M.email} />;

    return (
        <>
            <PageIntro title={PRIVACIDADE.titulo}>
                <p className="muted mt-6">{PRIVACIDADE.versao} {v(p.privacy_policy_version, M.versao)}</p>
            </PageIntro>
            <div className="container-site pb-24">
                <Block title={S.responsavel}>
                    <p>
                        O responsável pelo tratamento dos dados pessoais recolhidos neste site é {v(c.legal_name, M.nome)}, NIF {v(c.nif, M.nif)}, com sede em {v(c.address, M.morada)}.
                        {" "}Para qualquer pedido sobre os seus dados pessoais, escreva para {email}.
                    </p>
                </Block>
                <Block title={S.dados}><p>{S.dadosTexto}</p></Block>
                <Block title={S.finalidades}><ul className="list-disc space-y-2 pl-5">{S.finalidadesItens.map((t) => <li key={t}>{t}</li>)}</ul></Block>
                <Block title={S.fundamento}><p>{S.fundamentoTexto}</p></Block>
                <Block title={S.acesso}><p>{S.acessoTexto}</p></Block>
                <Block title={S.prazo}><p>{S.prazoTexto(p.contact_retention_months)}</p></Block>
                <Block title={S.direitos}>
                    <p>Pode pedir o acesso, a retificação, o apagamento, a limitação ou a portabilidade dos seus dados, e opor-se ao tratamento, por email para {email}.
                        {" "}Pode também apresentar reclamação à Comissão Nacional de Proteção de Dados (<a href="https://www.cnpd.pt" className="link-underline" target="_blank" rel="noopener noreferrer">www.cnpd.pt</a>).</p>
                </Block>
                <Block title={S.cookies}><p><Link href="/cookies" className="link-underline">{S.cookiesTexto}</Link></p></Block>
            </div>
        </>
    );
}
