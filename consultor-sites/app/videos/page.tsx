import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getDevelopments, getProperties, getSite } from "@/lib/api";
import { VIDEOS } from "@/content/textos";
import { VideoFeed, type FeedItem } from "@/components/listing/video-feed";
import { factsLine } from "@/components/listing/property-card";

export const metadata: Metadata = { title: VIDEOS.titulo, description: VIDEOS.lead };

/** O feed vertical dos vídeos dos imóveis e dos empreendimentos (os que têm vídeo). */
export default async function VideosPage() {
    const site = await getSite();
    if (!site) notFound();
    const [props, devs] = await Promise.all([getProperties(site, { per_page: "48" }), getDevelopments(site)]);
    const items: FeedItem[] = [
        ...props.items.filter((p) => p.youtube_id).map((p) => ({ key: `p-${p.slug}`, youtubeId: p.youtube_id!, cover: p.cover, title: p.title, line: factsLine(p),
            href: `/imoveis/${p.slug}`, subject: "property" as const, slug: p.slug, draft: p.draft })),
        ...devs.filter((d) => d.youtube_id).map((d) => ({ key: `d-${d.slug}`, youtubeId: d.youtube_id!, cover: d.cover, title: d.name,
            line: [d.parish?.name, d.municipality].filter(Boolean).join(", "), href: `/empreendimentos/${d.slug}`, subject: "development" as const, slug: d.slug, draft: d.draft })),
    ];
    return <VideoFeed items={items} />;
}
