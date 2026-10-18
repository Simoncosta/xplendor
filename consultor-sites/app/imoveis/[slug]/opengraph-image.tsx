import { getProperty, getSite } from "@/lib/api";
import { OG_SIZE, listingImage } from "@/lib/og";
import { factsLine, priceText } from "@/components/listing/property-card";

export const size = OG_SIZE;
export const contentType = "image/jpeg";
export const alt = "Imóvel";

/** A imagem de partilha deste imóvel (gerada a partir da capa e dos dados do anúncio). */
export default async function Image({ params }: { params: Promise<{ slug: string }> }) {
    const site = await getSite();
    const { slug } = await params;
    const p = site ? await getProperty(site, slug) : null;
    if (!site || !p) return new Response(null, { status: 404 });
    return listingImage({ title: p.title, line: factsLine(p), price: priceText(p), name: site.profile.public_name, cover: p.cover?.src ?? null, colors: site.profile.colors, draft: p.draft });
}
