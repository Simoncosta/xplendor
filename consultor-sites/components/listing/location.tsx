import { FICHA } from "@/content/textos";
import { mapConfig } from "@/lib/map";
import { MapView } from "./map-view";

/** A localização: o mapa (com o fornecedor configurado) ou a imagem estática; sempre com o texto da zona. */
export function Location({ lat, lng, radius, exact, place }: { lat: number; lng: number; radius: number | null; exact: boolean; place: string }) {
    const cfg = mapConfig();
    const label = exact ? FICHA.localizacaoExata(place) : FICHA.localizacaoAproximada(place, radius ?? 400);
    return (
        <figure>
            {cfg ? <MapView lat={lat} lng={lng} radius={radius} exact={exact} tileUrl={cfg.tileUrl} attribution={cfg.attribution} label={label} /> : <StaticMap exact={exact} label={label} />}
            <figcaption className="muted mt-3 text-sm">{label}</figcaption>
        </figure>
    );
}

/** Sem fornecedor de mosaicos: um desenho neutro (nenhum pedido a serviços de mapas). */
function StaticMap({ exact, label }: { exact: boolean; label: string }) {
    return (
        <svg viewBox="0 0 640 360" role="img" aria-label={label} className="aspect-[16/9] w-full bg-cream-2 text-navy">
            <defs>
                <pattern id="grade" width="40" height="40" patternUnits="userSpaceOnUse"><path d="M40 0H0V40" fill="none" stroke="currentColor" strokeOpacity="0.08" /></pattern>
            </defs>
            <rect width="640" height="360" fill="url(#grade)" />
            <path d="M-20 250 C 120 200, 220 300, 360 220 S 560 140, 680 180" fill="none" stroke="currentColor" strokeOpacity="0.18" strokeWidth="14" />
            <path d="M200 -20 C 240 120, 300 200, 280 380" fill="none" stroke="currentColor" strokeOpacity="0.12" strokeWidth="9" />
            {exact
                ? <g><circle cx="320" cy="180" r="12" fill="var(--color-amber)" stroke="currentColor" strokeWidth="4" /></g>
                : <g><circle cx="320" cy="180" r="92" fill="var(--color-amber)" fillOpacity="0.2" stroke="currentColor" strokeWidth="2" /></g>}
        </svg>
    );
}
