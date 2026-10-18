import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getSite } from "@/lib/api";
import { CONTACTO, CREDITO, ESTUDO, WHATSAPP } from "@/content/textos";
import { PageIntro } from "@/components/page-intro";
import { ContactForm } from "@/components/contact-form";

export const metadata: Metadata = { title: CONTACTO.titulo, description: CONTACTO.lead };

const PEDIDOS: Record<string, string> = { "estudo-da-zona": ESTUDO.mensagemPreenchida, credito: CREDITO.mensagemPreenchida };

/** Contacto: o formulário geral (ligado à XPLENDOR). "?pedido=" preenche a mensagem (até aos funis do F4). */
export default async function ContactoPage({ searchParams }: { searchParams: Promise<{ pedido?: string }> }) {
    const site = await getSite();
    if (!site) notFound();
    const { pedido } = await searchParams;
    return (
        <>
            <PageIntro title={CONTACTO.titulo} lead={CONTACTO.lead} />
            <section className="container-site pb-24 md:pb-32">
                <div className="grid gap-12 md:grid-cols-12">
                    <ContactForm defaultMessage={(pedido && PEDIDOS[pedido]) || ""} className="md:col-span-7" />
                    <aside className="md:col-span-4 md:col-start-9">
                        {site.profile.whatsapp && (
                            <div className="border-t border-navy pt-6">
                                <h2 className="font-display text-h3">{CONTACTO.whatsapp}</h2>
                                <p className="muted mt-3">{site.profile.whatsapp.messages.general.popup}</p>
                                <a className="link-underline mt-4 inline-block font-semibold" target="_blank" rel="noopener noreferrer"
                                    href={`https://wa.me/${site.profile.whatsapp.number.replace(/[^0-9]/g, "")}?text=${encodeURIComponent(site.profile.whatsapp.messages.general.prefill.replaceAll("{nome}", site.profile.public_name.split(/\s+/)[0] ?? ""))}`}>
                                    {WHATSAPP.abrir}
                                </a>
                            </div>
                        )}
                    </aside>
                </div>
            </section>
        </>
    );
}
