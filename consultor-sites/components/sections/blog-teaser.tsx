import { BLOG } from "@/content/textos";
import { shortDate } from "@/lib/format";
import type { BlogCard } from "@/lib/types";
import { SectionTitle } from "@/components/ui/section-title";

/** Blog e guias (só com artigos publicados). As páginas do blog entram no F5. */
export function BlogTeaser({ items }: { items: BlogCard[] }) {
    if (items.length === 0) return null;
    return (
        <section aria-labelledby="blog-titulo" className="section">
            <div className="container-site">
                <SectionTitle id="blog-titulo">{BLOG.titulo}</SectionTitle>
                <ul className="mt-14 divide-y divide-line border-y border-line">
                    {items.map((b) => (
                        <li key={b.slug} className="grid gap-3 py-8 md:grid-cols-12">
                            <p className="muted text-sm md:col-span-3">{shortDate(b.published_at)}{b.read_time ? ` · ${BLOG.minutos(b.read_time)}` : ""}</p>
                            <div className="md:col-span-8">
                                <h3 className="text-h3">{b.title}</h3>
                                {b.excerpt && <p className="muted mt-2 max-w-[60ch]">{b.excerpt}</p>}
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
