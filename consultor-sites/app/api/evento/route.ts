import { NextResponse, type NextRequest } from "next/server";
import { z } from "zod";
import { getSite, postEvents } from "@/lib/api";
import { allow } from "@/lib/rate-limit";

/**
 * As contagens próprias do site (visualização, clique no WhatsApp, vídeo aberto, clique na rede),
 * do navegador para a XPLENDOR pelo servidor do site. Sem IP, sem cookies, sem identificador: a
 * XPLENDOR só soma por dia. Na pré-visualização não conta nada.
 */
const schema = z.object({
    event: z.enum(["view", "whatsapp_click", "video_open", "chave_nova_click"]),
    subject: z.enum(["property", "development", "site"]),
    slug: z.string().max(160).optional(),
});

export async function POST(req: NextRequest) {
    const site = await getSite();
    const parsed = schema.safeParse(await req.json().catch(() => null));
    if (!site || site.preview || !parsed.success) return new NextResponse(null, { status: 204 });
    const ip = (req.headers.get("x-forwarded-for") ?? "").split(",")[0]?.trim() || "0.0.0.0";
    if (!allow(`evento:${ip}`, 60, 60_000)) return new NextResponse(null, { status: 204 }); // o IP só serve este limite, em memória
    await postEvents(site, [parsed.data]).catch(() => undefined);
    return new NextResponse(null, { status: 204 });
}
