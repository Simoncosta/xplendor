import Link from "next/link";
import { CREDITO } from "@/content/textos";
import type { Profile } from "@/lib/types";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";

/** O crédito com o parceiro (interruptor e dados completos no perfil): sem taxas nem prestações. */
export function Credit({ partner }: { partner: NonNullable<Profile["credit_partner"]> }) {
    return (
        <section aria-labelledby="credito-titulo" className="section bg-cream-2">
            <div className="container-site grid gap-10 md:grid-cols-12">
                <div className="md:col-span-5">
                    <h2 id="credito-titulo" className="text-h2">{CREDITO.titulo}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    {partner.logo_url && <img src={partner.logo_url} alt={partner.legal_name} className="mt-10 h-10 w-auto" loading="lazy" />}
                </div>
                <div className="md:col-span-6 md:col-start-7 md:mt-3">
                    {partner.text && <p className="text-lead whitespace-pre-line">{partner.text}</p>}
                    <p className="muted mt-5 text-sm">{CREDITO.registo(partner.legal_name, partner.bp_registration)}</p>
                    <Link href={`/contacto?pedido=credito`} className={cn(buttonVariants({ variant: "outline", size: "md" }), "mt-8")}>{CREDITO.botao}</Link>
                </div>
            </div>
        </section>
    );
}
