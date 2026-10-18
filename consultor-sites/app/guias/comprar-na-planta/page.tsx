import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { getSite } from "@/lib/api";
import { GUIA_PLANTA } from "@/content/textos";
import { PageIntro } from "@/components/page-intro";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";

export const metadata: Metadata = { title: GUIA_PLANTA.titulo, description: GUIA_PLANTA.descricao };

/** O guia "Como funciona comprar na planta" (rascunho para validar): o contrato-promessa, o sinal e os riscos, de forma honesta. */
export default async function GuiaPlantaPage() {
    const site = await getSite();
    if (!site) notFound();
    return (
        <>
            <PageIntro title={GUIA_PLANTA.titulo} lead={GUIA_PLANTA.lead} />
            <div className="container-site pb-24">
                {GUIA_PLANTA.seccoes.map((s) => (
                    <section key={s.titulo} className="grid gap-4 border-t border-line py-10 md:grid-cols-12">
                        <h2 className="font-display text-h3 md:col-span-4">{s.titulo}</h2>
                        <div className="max-w-[62ch] space-y-4 md:col-span-7 md:col-start-6">{s.texto.map((t) => <p key={t}>{t}</p>)}</div>
                    </section>
                ))}
                <Link href="/contacto" className={cn(buttonVariants({ variant: "outline", size: "md" }), "mt-6")}>{GUIA_PLANTA.falar}</Link>
            </div>
        </>
    );
}
