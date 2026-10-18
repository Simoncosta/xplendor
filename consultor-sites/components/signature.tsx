import { cn } from "@/lib/cn";

/**
 * A assinatura do consultor, pintada com a cor do texto (azul escuro no claro, creme no escuro)
 * por máscara CSS sobre o SVG do perfil, servido pelo próprio site. A imagem invisível dá a
 * proporção e o texto alternativo. "draw": desenha-se da esquerda para a direita (CSS; parado com
 * movimento reduzido).
 */
export function Signature({ alt, className, draw = false }: { alt: string; className?: string; draw?: boolean }) {
    const src = "/media/assinatura";
    const mask = { maskImage: `url(${src})`, WebkitMaskImage: `url(${src})`, maskRepeat: "no-repeat", WebkitMaskRepeat: "no-repeat",
        maskSize: "contain", WebkitMaskSize: "contain", maskPosition: "left center", WebkitMaskPosition: "left center" } as const;
    return (
        <span className={cn("relative inline-block align-middle", draw && "sig-draw", className)}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={src} alt={alt} className="h-full w-auto opacity-0" decoding="async" />
            <span aria-hidden="true" className="absolute inset-0 bg-current" style={mask} />
        </span>
    );
}
