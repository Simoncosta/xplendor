import { revalidateTag } from "next/cache";
import { NextResponse, type NextRequest } from "next/server";
import { env } from "@/lib/env";
import { normalizeHost } from "@/lib/host";
import { verifySignature } from "@/lib/signature";

/**
 * A XPLENDOR pede para atualizar as páginas de um site (pela rede interna). O corpo vem assinado:
 * HMAC-SHA256 de "carimbo.corpo" com CONSULTOR_REVALIDATE_SECRET; recusa acima de 5 minutos.
 * Cada etiqueta revalida-se em cada host do site ("{host}:{etiqueta}").
 */
export async function POST(req: NextRequest) {
    const body = await req.text();
    const ts = req.headers.get("x-revalidate-timestamp") ?? "";
    const sig = req.headers.get("x-revalidate-signature") ?? "";
    if (!verifySignature(env.revalidateSecret, ts, body, sig, Date.now() / 1000)) return NextResponse.json({ ok: false }, { status: 401 });

    let data: { hosts?: unknown; tags?: unknown };
    try { data = JSON.parse(body); } catch { return NextResponse.json({ ok: false }, { status: 400 }); }
    const hosts = (Array.isArray(data.hosts) ? data.hosts : []).map((h) => normalizeHost(String(h))).filter(Boolean).slice(0, 10);
    const tags = (Array.isArray(data.tags) ? data.tags : []).map(String).filter((t) => /^[a-z0-9:_-]{1,200}$/i.test(t)).slice(0, 50);
    for (const h of hosts) for (const t of tags) revalidateTag(`${h}:${t}`, "max");

    return NextResponse.json({ ok: true, revalidated: hosts.length * tags.length });
}
