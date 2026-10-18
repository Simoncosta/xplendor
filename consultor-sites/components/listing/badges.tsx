import { FICHA } from "@/content/textos";
import { cn } from "@/lib/cn";

/** A etiqueta "Rascunho" (só na pré-visualização) e a da disponibilidade (Reservado, Vendido, Arrendado). */
export function DraftBadge({ className }: { className?: string }) {
    return <span className={cn("inline-block bg-amber px-3 py-1.5 text-sm font-bold uppercase tracking-[0.08em] text-navy", className)}>{FICHA.rascunho}</span>;
}

const LABEL: Record<string, string> = { reservado: FICHA.reservado, vendido: FICHA.vendido, arrendado: FICHA.arrendado };

export function AvailabilityBadge({ availability, className }: { availability: string; className?: string }) {
    const label = LABEL[availability];
    if (!label) return null;
    return <span className={cn("inline-block bg-navy px-3 py-1.5 text-sm font-semibold text-cream", className)}>{label}</span>;
}
