import type { ZonePhoto } from "@/lib/types";

/** Uma faixa a toda a largura com uma fotografia de uma zona de atuação (separador), com zoom lento. */
export function ZoneBand({ photo }: { photo: ZonePhoto | null }) {
    if (!photo) return null;
    return (
        <figure className="relative h-[46svh] min-h-[16rem] overflow-hidden bg-navy md:h-[62svh]">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={photo.src} srcSet={`${photo.src_960} 960w, ${photo.src} 2400w`} sizes="100vw" alt={photo.alt ?? ""} loading="lazy" decoding="async" className="slow-zoom h-full w-full object-cover" />
            {photo.parish && <figcaption className="absolute bottom-4 left-5 bg-navy/70 px-3 py-1 text-sm text-cream md:left-10">{photo.parish}</figcaption>}
        </figure>
    );
}
