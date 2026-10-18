"use client";

import "leaflet/dist/leaflet.css";
import { useEffect, useRef } from "react";

/**
 * O mapa (Leaflet, só quando aparece no ecrã). Usados: um círculo em âmbar translúcido à volta do
 * centro aproximado, com o zoom ajustado ao raio, nunca um pino na casa. Empreendimentos: o ponto
 * exato da obra. Não prende o scroll da página (nem a roda do rato, nem um dedo no telemóvel).
 */
export function MapView({ lat, lng, radius, exact, tileUrl, attribution, label }: {
    lat: number; lng: number; radius: number | null; exact: boolean; tileUrl: string; attribution: string; label: string;
}) {
    const box = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const el = box.current;
        if (!el) return;
        let map: import("leaflet").Map | null = null;
        let cancelled = false;
        const io = new IntersectionObserver(async ([entry]) => {
            if (!entry?.isIntersecting || map) return;
            io.disconnect();
            const L = (await import("leaflet")).default;
            if (cancelled) return;
            const touch = L.Browser.mobile || L.Browser.touch;
            // Sem prender a página: a roda do rato não faz zoom e, no telemóvel, um dedo desliza a página (o mapa não se arrasta).
            map = L.map(el, { scrollWheelZoom: false, dragging: !touch, touchZoom: !touch, tapHold: false, zoomSnap: 0.25, attributionControl: true })
                .setView([lat, lng], exact ? 15 : 14);
            map.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank" rel="noopener noreferrer">Leaflet</a>');
            L.tileLayer(tileUrl, { attribution, maxZoom: 18, detectRetina: false }).addTo(map);
            const css = getComputedStyle(document.documentElement);
            const navy = css.getPropertyValue("--brand-navy").trim() || "#0E1E37";
            const amber = css.getPropertyValue("--brand-amber").trim() || "#FFA000";
            if (exact) {
                L.circleMarker([lat, lng], { radius: 9, color: navy, weight: 3, fillColor: amber, fillOpacity: 1 }).addTo(map);
            } else {
                // A zona aproximada: só o círculo, em âmbar translúcido, e o zoom que o mostra inteiro.
                const circle = L.circle([lat, lng], { radius: radius ?? 400, color: amber, weight: 2, opacity: 0.9, fillColor: amber, fillOpacity: 0.28, interactive: false }).addTo(map);
                map.fitBounds(circle.getBounds(), { padding: [28, 28], maxZoom: 16 });
            }
        }, { rootMargin: "200px" });
        io.observe(el);
        return () => { cancelled = true; io.disconnect(); map?.remove(); };
    }, [lat, lng, radius, exact, tileUrl, attribution]);
    return <div ref={box} role="img" aria-label={label} className="relative z-0 aspect-[4/3] w-full bg-cream-2 md:aspect-[16/9]" />;
}
