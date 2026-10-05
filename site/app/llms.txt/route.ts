import { BRAND, IDENTITY, PAGES, PHONE, SERVICES, TAGLINE, absoluteUrl } from "@/data/seo";
import { SERVICES_FAQ } from "@/data/faq";
import legal from "../../../server/config/legal-company.json";
import socials from "@/data/socials.json";

export const dynamic = "force-static";

/**
 * /llms.txt (padrão llmstxt.org), gerado no build a partir dos mesmos dados do
 * site (data/seo.ts, data/faq.ts e o JSON legal), sem texto duplicado à mão.
 */
export function GET() {
  const page = (key: keyof typeof PAGES, note: string) => `- [${PAGES[key].crumb}](${absoluteUrl(PAGES[key].path)}): ${note}`;
  const lines = [
    `# ${BRAND}`,
    "",
    `> ${IDENTITY}`,
    "",
    `${TAGLINE} Marca de ${legal.brandOwnerName} (NIF ${legal.nif}). Atua em Portugal, em português.`,
    "",
    "## Serviços",
    "",
    ...SERVICES.map((s) => `- [${s.name}](${absoluteUrl(`/servicos/#${s.id}`)}): ${s.description}`),
    "",
    "## Páginas principais",
    "",
    page("home", PAGES.home.description),
    page("servicos", PAGES.servicos.description),
    page("plataforma", PAGES.plataforma.description),
    page("contact", PAGES.contact.description),
    "",
    "## Perguntas frequentes",
    "",
    ...SERVICES_FAQ.map((f) => `- ${f.question} ${f.answer}`),
    "",
    "## Contacto",
    "",
    `- Email: ${legal.email}`,
    `- Telefone: ${PHONE}`,
    ...socials.map((s) => `- ${s.title}: ${s.url}`),
    "",
    "## Optional",
    "",
    page("privacidade", PAGES.privacidade.description),
    page("termos", PAGES.termos.description),
    page("eliminacao", PAGES.eliminacao.description),
    "",
  ];
  return new Response(lines.join("\n"), { headers: { "Content-Type": "text/plain; charset=utf-8" } });
}
