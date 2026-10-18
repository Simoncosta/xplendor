import { NextResponse, type NextRequest } from "next/server";
import { z } from "zod";
import { getSite, postContact } from "@/lib/api";
import { allow } from "@/lib/rate-limit";

/**
 * O contacto geral (tipo "contacto") e o pedido de visita (tipo "visita", com o imóvel ou o
 * empreendimento), do navegador para a XPLENDOR pelo servidor do site. A
 * versão da política vem do perfil (não do navegador). O IP do visitante só segue no X-Visitor-IP
 * para o limite de pedidos da XPLENDOR, que o usa em hash e não o guarda.
 */
const str = (max: number) => z.string().trim().max(max);
const schema = z.object({
    type: z.enum(["contacto", "visita"]).default("contacto"),
    property_slug: str(160).optional(),
    development_slug: str(160).optional(),
    preferred_time: str(120).optional(),
    name: str(120).min(2),
    email: z.string().trim().email().max(160).nullable().optional(),
    phone: str(30).nullable().optional(),
    message: str(2000).nullable().optional(),
    consent_privacy: z.literal(true),
    consent_marketing: z.boolean().optional(),
    website: z.string().max(200).optional(),
    event_id: z.string().uuid().optional(),
    origin: z.object({
        utm_source: str(120), utm_medium: str(120), utm_campaign: str(120), utm_content: str(120), utm_term: str(120),
        gclid: str(255), fbclid: str(255), landing_path: str(255), page_path: str(255), referrer_host: str(120),
    }).partial().optional(),
});

export async function POST(req: NextRequest) {
    const site = await getSite();
    if (!site) return NextResponse.json({ ok: false }, { status: 404 });
    const ip = (req.headers.get("x-forwarded-for") ?? "").split(",")[0]?.trim() || req.headers.get("x-real-ip") || "0.0.0.0";
    if (!allow(`contacto:${ip}`, 5, 60_000)) return NextResponse.json({ ok: false }, { status: 429 });

    const parsed = schema.safeParse(await req.json().catch(() => null));
    if (!parsed.success) return NextResponse.json({ ok: false, errors: { form: ["Pedido inválido."] } }, { status: 422 });
    const d = parsed.data;
    const res = await postContact(site, {
        type: d.type, name: d.name,
        ...(d.property_slug ? { property_slug: d.property_slug } : {}), ...(d.development_slug ? { development_slug: d.development_slug } : {}),
        ...(d.type === "visita" && d.preferred_time ? { details: { preferred_time: d.preferred_time } } : {}), email: d.email || null, phone: d.phone || null, message: d.message || null,
        consent_privacy: true, consent_marketing: !!d.consent_marketing, policy_version: site.profile.privacy_policy_version ?? "sem-versao",
        website: d.website ?? "", event_id: d.event_id, origin: d.origin ?? {},
    }, ip);

    const body = await res.json().catch(() => ({}));
    if (res.status === 202 && body?.data?.preview) return NextResponse.json({ ok: true, preview: true }, { status: 202 });
    if (res.ok) return NextResponse.json({ ok: true }, { status: 201 });
    if (res.status === 422) return NextResponse.json({ ok: false, errors: body?.errors ?? {} }, { status: 422 });
    return NextResponse.json({ ok: false }, { status: res.status === 429 ? 429 : 502 });
}
