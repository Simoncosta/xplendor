import { TESTEMUNHOS } from "@/content/textos";

export type Testimonial = { quote: string; author: string; context: string | null };

/**
 * Testemunhos: o componente fica pronto, mas só aparece com testemunhos reais e autorizados (vêm
 * da XPLENDOR quando existirem). Nunca testemunhos inventados.
 */
export function Testimonials({ items }: { items: Testimonial[] }) {
    if (items.length === 0) return null;
    return (
        <section aria-labelledby="testemunhos-titulo" className="section">
            <div className="container-site">
                <h2 id="testemunhos-titulo" className="text-h2">{TESTEMUNHOS.titulo}</h2>
                <span className="accent-rule" aria-hidden="true" />
                <ul className="mt-14 grid gap-14 md:grid-cols-2">
                    {items.map((t) => (
                        <li key={t.author + t.quote.slice(0, 20)}>
                            <blockquote className="font-display text-[1.6rem] leading-snug">{t.quote}</blockquote>
                            <p className="muted mt-4">{t.author}{t.context ? `, ${t.context}` : ""}</p>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
