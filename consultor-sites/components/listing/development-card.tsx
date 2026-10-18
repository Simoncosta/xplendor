import Link from "next/link";
import { EMPREENDIMENTO } from "@/content/textos";
import { euros, monthYear } from "@/lib/format";
import type { DevelopmentCard as Card } from "@/lib/types";
import { DraftBadge } from "./badges";
import { CoverImage } from "./property-card";

/** Um empreendimento em linha (fotografia grande e o texto ao lado, alternando). */
export function DevelopmentRow({ d, flip = false }: { d: Card; flip?: boolean }) {
    return (
        <article className="group relative grid items-end gap-8 md:grid-cols-12">
            <div className={`relative md:col-span-7 ${flip ? "md:order-2 md:col-start-6" : ""}`}>
                <CoverImage image={d.cover} alt={d.name} className="aspect-[16/10] w-full" sizes="(min-width: 768px) 58vw, 92vw" />
                {d.draft && <DraftBadge className="absolute left-0 top-4" />}
            </div>
            <div className={`md:col-span-4 ${flip ? "md:order-1 md:col-start-1" : "md:col-start-9"}`}>
                <h3 className="text-h3"><Link href={`/empreendimentos/${d.slug}`} className="after:absolute after:inset-0">{d.name}</Link></h3>
                <p className="muted mt-2">{[d.parish?.name, d.municipality].filter(Boolean).join(", ")}</p>
                <p className="mt-4">{d.construction_phase_label}{d.expected_completion ? `, ${EMPREENDIMENTO.conclusao.toLowerCase()} ${monthYear(d.expected_completion)}` : ""}</p>
                <p className="mt-1">{d.price_from ? `${EMPREENDIMENTO.desde} ${euros(d.price_from)}` : null}{d.units_available ? `${d.price_from ? " · " : ""}${EMPREENDIMENTO.fracoesDisponiveis(d.units_available)}` : ""}</p>
            </div>
        </article>
    );
}
