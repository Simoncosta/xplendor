/**
 * XPLENDOR: verificação do SEO do export (correr depois de `npm run build`):
 *   node scripts/check-seo.mjs
 * Valida o JSON-LD de cada página (JSON válido, tipos e campos obrigatórios, URLs
 * absolutos no domínio canónico, sem avaliações), os metadados (lang, title,
 * description, canonical, hreflang, Open Graph), as imagens (width/height) e os
 * ficheiros da raiz (robots.txt, sitemap.xml, llms.txt). Sai com código 1 se falhar.
 */
import fs from "node:fs";
import path from "node:path";

const OUT = path.join(path.dirname(new URL(import.meta.url).pathname), "..", "out");
const SITE = "https://xplendor.tech";
const errors = [];
const fail = (where, msg) => errors.push(`${where}: ${msg}`);

const pages = {
  "index.html": "pt-PT", "servicos/index.html": "pt-PT", "plataforma/index.html": "pt-PT", "contact/index.html": "pt-PT",
  "politica-de-privacidade/index.html": "pt-PT", "termos-e-condicoes/index.html": "pt-PT", "eliminacao-de-dados/index.html": "pt-PT",
  "en/privacy-policy/index.html": "en", "en/terms/index.html": "en", "en/data-deletion/index.html": "en",
};
const REQUIRED = {
  Organization: ["name", "legalName", "url", "logo", "sameAs", "contactPoint", "areaServed"],
  WebSite: ["name", "url", "publisher"],
  Service: ["name", "serviceType", "description", "provider", "areaServed"],
  FAQPage: ["mainEntity"],
  BreadcrumbList: ["itemListElement"],
};
const FORBIDDEN = /"@type":"(AggregateRating|Review|Rating)"|aggregateRating|"review"/i;
const urlsIn = (obj, acc = []) => {
  if (typeof obj === "string" && /^https?:\/\//.test(obj)) acc.push(obj);
  else if (obj && typeof obj === "object") Object.values(obj).forEach((v) => urlsIn(v, acc));
  return acc;
};
const titles = new Set();
const descriptions = new Set();
const typesSeen = {};

for (const [file, lang] of Object.entries(pages)) {
  const html = fs.readFileSync(path.join(OUT, file), "utf8");
  const head = html.split("</head>")[0];
  const get = (re) => (head.match(re) || [])[1];
  if (get(/<html[^>]*lang="([^"]+)"/) !== lang && !(html.match(/<html[^>]*lang="([^"]+)"/) || [])[1]?.startsWith(lang)) fail(file, `lang diferente de ${lang}`);
  const title = get(/<title>([^<]+)<\/title>/);
  const desc = get(/<meta name="description" content="([^"]+)"/);
  if (!title) fail(file, "sem title"); else if (titles.has(title)) fail(file, "title repetido"); else titles.add(title);
  if (!desc) fail(file, "sem description"); else if (descriptions.has(desc)) fail(file, "description repetida"); else descriptions.add(desc);
  if (/—/.test(title + desc)) fail(file, "travessão no title/description");
  const canonical = get(/<link rel="canonical" href="([^"]+)"/);
  const expected = `${SITE}/${file.replace(/index\.html$/, "")}`;
  if (canonical !== expected) fail(file, `canonical ${canonical} (esperado ${expected})`);
  for (const k of ["og:title", "og:description", "og:url", "og:image", "og:locale"]) if (!head.includes(`property="${k}"`)) fail(file, `sem ${k}`);
  const og = get(/<meta property="og:image" content="([^"]+)"/);
  if (og && !fs.existsSync(path.join(OUT, og.replace(SITE, "")))) fail(file, `og:image inexistente ${og}`);
  const alts = [...head.matchAll(/<link rel="alternate" hrefLang="([^"]+)" href="([^"]+)"/g)];
  for (const [, , href] of alts) if (!href.startsWith(`${SITE}/`)) fail(file, `hreflang relativo ${href}`);
  if (file.includes("privacidade") || file.includes("termos") || file.includes("eliminacao") || file.startsWith("en/")) {
    if (alts.length < 3) fail(file, "hreflang em falta (pt-PT, en, x-default)");
  }

  const blocks = [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)].map((m) => m[1]);
  if (blocks.length === 0) fail(file, "sem JSON-LD");
  for (const raw of blocks) {
    let data;
    try { data = JSON.parse(raw); } catch (e) { fail(file, `JSON-LD inválido: ${e.message}`); continue; }
    if (FORBIDDEN.test(raw)) fail(file, "JSON-LD com avaliações (proibido)");
    if (data["@context"] !== "https://schema.org") fail(file, "@context em falta");
    const type = data["@type"];
    typesSeen[type] = (typesSeen[type] ?? 0) + 1;
    for (const field of REQUIRED[type] ?? []) if (data[field] === undefined) fail(file, `${type} sem ${field}`);
    if (!REQUIRED[type]) fail(file, `tipo inesperado ${type}`);
    for (const u of urlsIn(data)) {
      const own = u.startsWith(SITE);
      const external = /facebook\.com|instagram\.com|linkedin\.com|^https:\/\/schema\.org$/.test(u);
      if (!own && !external) fail(file, `URL fora do domínio canónico no JSON-LD: ${u}`);
      if (own && /\/img\//.test(u) && !fs.existsSync(path.join(OUT, new URL(u).pathname))) fail(file, `imagem do JSON-LD inexistente ${u}`);
    }
    if (type === "FAQPage") for (const q of data.mainEntity) if (q["@type"] !== "Question" || !q.name || !q.acceptedAnswer?.text) fail(file, "pergunta FAQ incompleta");
    if (type === "BreadcrumbList") data.itemListElement.forEach((it, i) => { if (it.position !== i + 1 || !it.name || !String(it.item).startsWith(SITE)) fail(file, "breadcrumb inválido"); });
    if (type === "Organization" && (data.legalName !== "Simon Costa, Unipessoal, Lda" || data.areaServed?.name !== "Portugal")) fail(file, "Organization: legalName ou areaServed");
  }

  for (const img of html.match(/<img\b[^>]*>/g) ?? []) {
    if (!/\bwidth="\d+"/.test(img) || !/\bheight="\d+"/.test(img)) fail(file, `<img> sem width/height: ${img.slice(0, 90)}`);
    const src = (img.match(/\bsrc="([^"]+)"/) || [])[1];
    if (src?.startsWith("/") && !fs.existsSync(path.join(OUT, src))) fail(file, `imagem inexistente ${src}`);
  }
}

if (!typesSeen.Service || typesSeen.Service !== 5) fail("servicos", `esperados 5 Service, encontrados ${typesSeen.Service ?? 0}`);
if (!typesSeen.FAQPage) fail("servicos", "sem FAQPage");

const robots = fs.readFileSync(path.join(OUT, "robots.txt"), "utf8");
for (const bot of ["Googlebot", "Bingbot", "OAI-SearchBot", "ChatGPT-User", "Claude-SearchBot", "Claude-User", "PerplexityBot", "GPTBot", "ClaudeBot", "Google-Extended", "CCBot"]) {
  if (!new RegExp(`User-Agent: ${bot}\\nAllow: /`).test(robots)) fail("robots.txt", `${bot} não permitido`);
}
if (!robots.includes(`Sitemap: ${SITE}/sitemap.xml`)) fail("robots.txt", "sem ligação ao sitemap");
const sitemap = fs.readFileSync(path.join(OUT, "sitemap.xml"), "utf8");
const locs = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
if (locs.length !== Object.keys(pages).length) fail("sitemap.xml", `${locs.length} URLs (esperadas ${Object.keys(pages).length})`);
if ((sitemap.match(/<lastmod>/g) ?? []).length !== locs.length) fail("sitemap.xml", "lastmod em falta");
if (locs.some((l) => l.includes("home-main"))) fail("sitemap.xml", "contém /home-main");
const llms = fs.readFileSync(path.join(OUT, "llms.txt"), "utf8");
if (!llms.startsWith("# XPLENDOR\n\n> ")) fail("llms.txt", "não segue o formato (título H1 + resumo em citação)");
for (const s of ["## Serviços", "## Contacto", `${SITE}/servicos/`]) if (!llms.includes(s)) fail("llms.txt", `falta ${s}`);
if (fs.existsSync(path.join(OUT, "home-main"))) fail("out", "a rota /home-main ainda existe");

console.log(`Tipos JSON-LD por página somados: ${JSON.stringify(typesSeen)}`);
if (errors.length) { console.error(`\n${errors.length} problema(s):\n- ${errors.join("\n- ")}`); process.exit(1); }
console.log(`OK: ${Object.keys(pages).length} páginas, robots.txt, sitemap.xml (${locs.length} URLs) e llms.txt verificados.`);
