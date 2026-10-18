import { isDev } from "@/lib/env";

/**
 * A capa de um vídeo do YouTube, pedida pelo servidor do site (o navegador do visitante não fala
 * com a Google antes de tocar no vídeo) e guardada em cache um dia. Primeiro a capa vertical dos
 * Shorts; sem ela, a normal (o vídeo vertical fica ao centro). Em dev nunca se pede nada à Google:
 * sai uma capa de marcação.
 */
const ID = /^[A-Za-z0-9_-]{11}$/;

function devCover(id: string) {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="540" height="960" viewBox="0 0 540 960"><rect width="540" height="960" fill="#1D3856"/>`
        + `<rect x="24" y="24" width="492" height="912" fill="none" stroke="#B9C3D0" stroke-width="3" stroke-dasharray="14 10"/>`
        + `<text x="270" y="470" fill="#B9C3D0" font-family="sans-serif" font-size="30" text-anchor="middle">Capa do YouTube</text>`
        + `<text x="270" y="512" fill="#B9C3D0" font-family="sans-serif" font-size="22" text-anchor="middle">(dev: ${id})</text></svg>`;
    return new Response(svg, { headers: { "Content-Type": "image/svg+xml", "Cache-Control": "no-store" } });
}

export async function GET(_req: Request, { params }: { params: Promise<{ id: string }> }) {
    const { id } = await params;
    if (!ID.test(id)) return new Response(null, { status: 404 });
    if (isDev) return devCover(id);
    for (const name of ["oar2.jpg", "hqdefault.jpg"]) {
        try {
            const res = await fetch(`https://i.ytimg.com/vi/${id}/${name}`, { next: { revalidate: 86400 } });
            if (!res.ok) continue;
            return new Response(await res.arrayBuffer(), {
                headers: { "Content-Type": res.headers.get("Content-Type") ?? "image/jpeg", "Cache-Control": "public, max-age=86400, stale-while-revalidate=604800", "X-Content-Type-Options": "nosniff" },
            });
        } catch { /* a seguinte */ }
    }
    return new Response(null, { status: 404 });
}
