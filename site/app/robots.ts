import type { MetadataRoute } from "next";
import { SITE_URL } from "@/data/seo";

export const dynamic = "force-static";

/**
 * robots.txt (gerado no build). Permite os motores de pesquisa, os robôs de pesquisa
 * das IAs e (decisão do utilizador) os robôs de treino. A app (/app), a API e os
 * relatórios privados (/r/) não interessam aos motores de pesquisa.
 */
const SEARCH_BOTS = ["Googlebot", "Bingbot", "OAI-SearchBot", "ChatGPT-User", "Claude-SearchBot", "Claude-User", "PerplexityBot"];
const TRAINING_BOTS = ["GPTBot", "ClaudeBot", "Google-Extended", "CCBot"];
const PRIVATE = ["/app/", "/api/", "/r/"];

export default function robots(): MetadataRoute.Robots {
  return {
    rules: [
      ...[...SEARCH_BOTS, ...TRAINING_BOTS].map((userAgent) => ({ userAgent, allow: "/", disallow: PRIVATE })),
      { userAgent: "*", allow: "/", disallow: PRIVATE },
    ],
    sitemap: `${SITE_URL}/sitemap.xml`,
    host: SITE_URL,
  };
}
