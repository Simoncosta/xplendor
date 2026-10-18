import "server-only";
import { cache } from "react";
import { cookies, headers } from "next/headers";
import { env } from "./env";
import { resolveHost } from "./host";
import type { BlogCard, DevelopmentCard, DevelopmentDetail, Facets, Page, Profile, PropertyCard, PropertyDetail, Site } from "./types";

/**
 * A ligação à API do site na XPLENDOR (servidor a servidor). O token do contentor e o host do
 * visitante vão em cada pedido; o consultor vem do host. As leituras ficam na cache do Next com
 * etiquetas por host ("{host}:site", "{host}:properties", …), que a XPLENDOR manda revalidar
 * quando o conteúdo muda. A pré-visualização nunca usa a cache (cache: "no-store").
 */

export const PREVIEW_COOKIE = "xp_preview";
const REVALIDATE_SECONDS = 300;

type Ctx = { host: string; previewToken: string | null };

class ApiError extends Error {
    constructor(public status: number, message: string) {
        super(message);
    }
}

async function call(ctx: Ctx, path: string, tags: string[], init: RequestInit = {}): Promise<Response> {
    if (!env.apiUrl || !env.apiToken) throw new ApiError(500, "A API do site não está configurada (CONSULTOR_API_URL, CONSULTOR_API_TOKEN).");
    const headersInit: Record<string, string> = {
        Authorization: `Bearer ${env.apiToken}`,
        "X-Site-Host": ctx.host,
        Accept: "application/json",
        ...(init.headers as Record<string, string> | undefined),
    };
    if (ctx.previewToken) headersInit["X-Site-Preview"] = ctx.previewToken;
    const isRead = (init.method ?? "GET") === "GET";
    return fetch(`${env.apiUrl}/${path.replace(/^\//, "")}`, {
        ...init,
        headers: headersInit,
        ...(isRead && !ctx.previewToken
            ? { cache: "force-cache" as const, next: { revalidate: REVALIDATE_SECONDS, tags: [`${ctx.host}:site`, ...tags.map((t) => `${ctx.host}:${t}`)] } }
            : { cache: "no-store" as const }),
    });
}

async function getJson<T>(ctx: Ctx, path: string, tags: string[] = []): Promise<T | null> {
    const res = await call(ctx, path, tags);
    if (res.status === 404) return null;
    if (!res.ok) throw new ApiError(res.status, `A API do site respondeu ${res.status} a ${path}.`);
    const body = (await res.json()) as { data: T };
    return body.data;
}

/** O site deste pedido (host, perfil e pré-visualização), uma vez por pedido. Sem site: null (404). */
export const getSite = cache(async (): Promise<Site | null> => {
    const h = await headers();
    const host = resolveHost(h.get("host"), env.devHosts); // o nginx passa o Host original; o X-Forwarded-Host não é de confiança
    if (!host) return null;
    const previewToken = (await cookies()).get(PREVIEW_COOKIE)?.value ?? null;
    const ctx = { host, previewToken };
    const res = await call(ctx, "profile", []);
    if (res.status === 404 || res.status === 401) return null;
    if (!res.ok) throw new ApiError(res.status, `A API do site respondeu ${res.status} ao perfil.`);
    const profile = ((await res.json()) as { data: Profile }).data;
    const preview = !!previewToken && res.headers.get("x-site-preview") === "active";

    return { host, profile, preview, previewToken: preview ? previewToken : null };
});

const ctxOf = (site: Site): Ctx => ({ host: site.host, previewToken: site.previewToken });

export async function getFeaturedProperties(site: Site): Promise<PropertyCard[]> {
    const page = await getJson<Page<PropertyCard>>(ctxOf(site), "properties?featured=1&per_page=6", ["properties"]);
    return page?.items ?? [];
}

export type PropertyFilters = Partial<Record<"kind" | "typology" | "parish" | "construction_state" | "price_min" | "price_max" | "page" | "per_page", string>>;

export async function getProperties(site: Site, filters: PropertyFilters = {}): Promise<Page<PropertyCard> & { facets: Facets }> {
    const qs = new URLSearchParams(Object.entries(filters).filter(([, v]) => v !== undefined && v !== "") as [string, string][]).toString();
    const page = await getJson<Page<PropertyCard> & { facets: Facets }>(ctxOf(site), `properties${qs ? `?${qs}` : ""}`, ["properties"]);
    return page ?? { items: [], page: 1, per_page: 12, total: 0, last_page: 1, facets: { kinds: {}, typologies: [], construction_states: {}, parishes: [], price_min: null, price_max: null } };
}

export async function getProperty(site: Site, slug: string): Promise<PropertyDetail | null> {
    return getJson<PropertyDetail>(ctxOf(site), `properties/${encodeURIComponent(slug)}`, ["properties", `property:${slug}`]);
}

export async function getDevelopment(site: Site, slug: string): Promise<DevelopmentDetail | null> {
    return getJson<DevelopmentDetail>(ctxOf(site), `developments/${encodeURIComponent(slug)}`, ["developments", `development:${slug}`]);
}

/** Envia contagens agregadas (sem IP nem identificador). */
export async function postEvents(site: Site, events: { event: string; subject: string; slug?: string }[]): Promise<Response> {
    return call(ctxOf(site), "events", [], { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ events }) });
}

export async function getDevelopments(site: Site): Promise<DevelopmentCard[]> {
    const data = await getJson<{ items: DevelopmentCard[] }>(ctxOf(site), "developments", ["developments"]);
    return data?.items ?? [];
}

export async function getBlog(site: Site, perPage = 3): Promise<BlogCard[]> {
    const page = await getJson<Page<BlogCard>>(ctxOf(site), `blog?per_page=${perPage}`, ["blog"]);
    return page?.items ?? [];
}

/** Verifica um token de pré-visualização (sem cache). */
export async function checkPreview(host: string, token: string): Promise<boolean> {
    const res = await call({ host, previewToken: token }, "profile", []);
    return res.ok && res.headers.get("x-site-preview") === "active";
}

/** Envia um contacto à XPLENDOR (pelo servidor do site, nunca pelo navegador). */
export async function postContact(site: Site, body: Record<string, unknown>, visitorIp: string): Promise<Response> {
    return call(ctxOf(site), "contacts", [], {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Visitor-IP": visitorIp },
        body: JSON.stringify(body),
    });
}
