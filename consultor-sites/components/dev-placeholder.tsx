/**
 * Os marcadores neutros de dev ("Fotografia por carregar"), para ver o desenho antes de haver os
 * ficheiros reais. Os textos estão aqui dentro, num ramo que o build de produção elimina: em
 * produção, nem o marcador nem o texto existem.
 */
export type DevKind = "fotografia" | "assinatura" | "video" | "biografia";

export function DevPlaceholder({ kind, className }: { kind: DevKind; className?: string }) {
    if (process.env.NODE_ENV === "production") return null;
    const labels: Record<DevKind, string> = {
        fotografia: "Fotografia por carregar", assinatura: "Assinatura por carregar", video: "Vídeo do topo por carregar", biografia: "Biografia por escrever",
    };
    return (
        <div className={`grid place-items-center border-[1.5px] border-dashed border-current p-4 text-center text-[0.8rem] opacity-75 ${className ?? ""}`}>
            {labels[kind]} (dev)
        </div>
    );
}
