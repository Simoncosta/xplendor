import { getDevelopment, getSite } from "@/lib/api";
import { euros, monthYear } from "@/lib/format";
import { OG_SIZE, listingImage } from "@/lib/og";
import { EMPREENDIMENTO } from "@/content/textos";

export const size = OG_SIZE;
export const contentType = "image/jpeg";
export const alt = "Empreendimento";

export default async function Image({ params }: { params: Promise<{ slug: string }> }) {
    const site = await getSite();
    const { slug } = await params;
    const d = site ? await getDevelopment(site, slug) : null;
    if (!site || !d) return new Response(null, { status: 404 });
    const line = [d.construction_phase_label, d.expected_completion ? `${EMPREENDIMENTO.conclusao.toLowerCase()} ${monthYear(d.expected_completion)}` : null].filter(Boolean).join(", ");
    return listingImage({ title: d.name, line, price: d.price_from ? `${EMPREENDIMENTO.desde} ${euros(d.price_from)}` : null, name: site.profile.public_name,
        cover: d.cover?.src ?? null, colors: site.profile.colors, draft: d.draft });
}
