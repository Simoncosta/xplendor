import { NextResponse } from "next/server";
import { getSite } from "@/lib/api";

/**
 * A assinatura do perfil servida pelo próprio site (mesma origem), para o site a pintar com a cor
 * do texto por máscara CSS (uma máscara de outro domínio esbarra no CORS). O SVG já foi verificado
 * na XPLENDOR (só contornos); aqui sai sempre como imagem, com o CSP em sandbox.
 */
export async function GET() {
    const site = await getSite();
    const url = site?.profile.signature_url;
    if (!url) return new NextResponse(null, { status: 404 });
    const res = await fetch(url, { cache: "force-cache", next: { revalidate: 3600, tags: [`${site.host}:site`] } });
    if (!res.ok) return new NextResponse(null, { status: 404 });
    return new NextResponse(await res.text(), {
        headers: {
            "Content-Type": "image/svg+xml",
            "Cache-Control": "public, max-age=3600",
            "Content-Security-Policy": "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            "X-Content-Type-Options": "nosniff",
        },
    });
}
