import type { MetadataRoute } from "next";
import { execFileSync } from "node:child_process";
import path from "node:path";
import { PAGES, absoluteUrl } from "@/data/seo";

export const dynamic = "force-static";

/**
 * sitemap.xml (gerado no build) com todas as páginas públicas. lastmod = data do
 * último commit dos ficheiros de cada página (o build de produção corre dentro do
 * repositório); sem histórico disponível, usa a data do build.
 */
function lastCommitDate(sources: string[]): Date {
  const siteRoot = process.cwd();
  let latest: Date | null = null;
  for (const src of sources) {
    try {
      const out = execFileSync("git", ["log", "-1", "--format=%cI", "--", path.join(/*turbopackIgnore: true*/ siteRoot, src)], { cwd: siteRoot, encoding: "utf8" }).trim();
      if (out) {
        const d = new Date(out);
        if (!latest || d > latest) latest = d;
      }
    } catch {
      // sem git: fica a data do build
    }
  }
  return latest ?? new Date();
}

export default function sitemap(): MetadataRoute.Sitemap {
  return Object.values(PAGES).map((p) => {
    const alt = p.alternate ? PAGES[p.alternate] : null;
    return {
      url: absoluteUrl(p.path),
      lastModified: lastCommitDate(p.sources),
      changeFrequency: p.path === "/" ? "weekly" : "monthly",
      priority: p.path === "/" ? 1 : p.lang === "pt-PT" && !p.alternate ? 0.8 : 0.3,
      ...(alt ? { alternates: { languages: { [p.lang]: absoluteUrl(p.path), [alt.lang]: absoluteUrl(alt.path) } } } : {}),
    };
  });
}
