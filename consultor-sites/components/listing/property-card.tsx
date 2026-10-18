import Link from "next/link";
import { FICHA } from "@/content/textos";
import { areaM2, euros } from "@/lib/format";
import type { Image, PropertyCard as Card } from "@/lib/types";
import { AvailabilityBadge, DraftBadge } from "./badges";
import { EnergyLabel } from "./energy-label";

export function CoverImage({ image, alt, className, sizes = "(min-width: 768px) 33vw, 92vw", priority = false }: { image: Image | null; alt: string; className?: string; sizes?: string; priority?: boolean }) {
    if (!image) return <div className={`bg-cream-2 ${className ?? ""}`} aria-hidden="true" />;
    // eslint-disable-next-line @next/next/no-img-element
    return <img src={image.src} srcSet={image.srcset} sizes={sizes} alt={image.alt ?? alt} width={image.width ?? undefined} height={image.height ?? undefined}
        loading={priority ? "eager" : "lazy"} fetchPriority={priority ? "high" : undefined} decoding="async" className={`object-cover ${className ?? ""}`} />;
}

export const priceText = (p: Pick<Card, "price" | "price_on_request" | "listing">) =>
    p.price_on_request || p.price === null ? FICHA.sobConsulta : `${euros(p.price)}${p.listing === "arrendamento" ? ` ${FICHA.porMes}` : ""}`;

export const factsLine = (p: Card) => [p.typology, areaM2(p.area_m2), [p.parish?.name, p.municipality].filter(Boolean).join(", ")].filter(Boolean).join(" · ");

/** O cartão de um imóvel: a fotografia manda; as etiquetas Rascunho (pré-visualização) e Reservado ou Vendido. */
export function PropertyCard({ p, large = false }: { p: Card; large?: boolean }) {
    return (
        <article className="group relative">
            <div className="relative overflow-hidden">
                <CoverImage image={p.cover} alt={p.title} className={`w-full transition-transform duration-700 group-hover:scale-[1.02] ${large ? "aspect-[4/5] md:aspect-[5/6]" : "aspect-[3/2]"}`} />
                <div className="absolute left-0 top-4 flex flex-col items-start gap-1.5">
                    {p.draft && <DraftBadge />}
                    <AvailabilityBadge availability={p.availability} />
                </div>
            </div>
            <h3 className="text-h3 mt-5">
                <Link href={`/imoveis/${p.slug}`} className="after:absolute after:inset-0">{p.title}</Link>
            </h3>
            <p className="muted mt-1">{factsLine(p)}</p>
            <div className="mt-3 flex items-center justify-between gap-4">
                <p className="font-semibold">{priceText(p)}</p>
                <EnergyLabel value={p.energy_class} size="sm" />
            </div>
        </article>
    );
}
