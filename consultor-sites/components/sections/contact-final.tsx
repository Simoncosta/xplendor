import { CONTACTO_FINAL } from "@/content/textos";
import { ContactForm } from "@/components/contact-form";

/** O contacto final da página inicial (o formulário geral, ligado à XPLENDOR). */
export function ContactFinal({ title = CONTACTO_FINAL.titulo, text = CONTACTO_FINAL.texto, defaultMessage }: { title?: string; text?: string; defaultMessage?: string }) {
    return (
        <section id="contacto" aria-labelledby="contacto-titulo" className="section on-dark">
            <div className="container-site grid gap-12 md:grid-cols-12">
                <div className="md:col-span-5">
                    <h2 id="contacto-titulo" className="text-h2 max-w-[13ch]">{title}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                    <p className="text-lead muted mt-8 max-w-[32ch]">{text}</p>
                </div>
                <ContactForm tone="dark" defaultMessage={defaultMessage} className="md:col-span-6 md:col-start-7" />
            </div>
        </section>
    );
}
