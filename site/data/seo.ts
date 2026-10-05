/**
 * XPLENDOR: fonte única do SEO do site. Daqui saem os metadados de cada página
 * (title, description, canonical, hreflang, Open Graph), os dados estruturados
 * (JSON-LD), o sitemap.xml, o robots.txt e o llms.txt. Os dados da entidade vêm de
 * server/config/legal-company.json (os mesmos das páginas legais e do PDF).
 */
import type { Metadata } from "next";
import legal from "../../server/config/legal-company.json";
import socials from "./socials.json";

export const SITE_URL = legal.siteUrl.replace(/\/$/, "");
export const BRAND = legal.brand;
export const PHONE = "+351938963526";
export const OG_IMAGE = { path: "/img/og/xplendor-og.png", width: 1200, height: 630, alt: "XPLENDOR: marketing e tecnologia, juntos." };
export const LOGO_PATH = "/img/logo/xplendor-logo-512.png";

export const absoluteUrl = (path: string) => `${SITE_URL}${path.startsWith("/") ? path : `/${path}`}`;

/** Frase de identidade (home, JSON-LD e llms.txt). */
export const IDENTITY =
  "A XPLENDOR é uma equipa de marketing e tecnologia em Portugal que ajuda empresas a vender mais: gere as redes sociais e as campanhas de anúncios, desenvolve websites e aplicações e tem uma plataforma própria de gestão, feita à medida de setores como o automóvel, as autocaravanas e a restauração.";

export const TAGLINE = "Marketing e tecnologia, juntos.";

/** Os serviços (JSON-LD Service e llms.txt). As descrições resumem as da página Serviços. */
export const SERVICES: { id: string; name: string; serviceType: string; description: string }[] = [
  {
    id: "plataforma",
    name: "Plataforma de gestão",
    serviceType: "Software de gestão à medida do setor",
    description: "Plataforma própria para gerir o negócio num só lugar (stock, faturação, vendas e relatórios), adaptada ao setor de cada cliente.",
  },
  {
    id: "social-media",
    name: "Social Media",
    serviceType: "Gestão de redes sociais",
    description: "Gestão do Instagram e do Facebook: estratégia, criação de conteúdo, calendário editorial, design de publicações e relatórios de desempenho.",
  },
  {
    id: "trafego-pago",
    name: "Tráfego Pago",
    serviceType: "Gestão de campanhas de anúncios",
    description: "Campanhas no Google Ads e na Meta (Facebook e Instagram), com gestão de orçamento, otimização contínua e relatórios de resultados.",
  },
  {
    id: "websites",
    name: "Websites",
    serviceType: "Desenvolvimento de websites",
    description: "Desenvolvimento de websites à medida do negócio, preparados para telemóvel e para receber contactos.",
  },
  {
    id: "consultoria-tecnologia",
    name: "Consultoria de tecnologia",
    serviceType: "Consultoria de tecnologia e ERP",
    description: "Desenvolvimento de aplicações, consultoria de ERP e integrações entre sistemas, com acompanhamento do princípio ao fim.",
  },
];

export type PageKey =
  | "home" | "servicos" | "plataforma" | "contact"
  | "privacidade" | "termos" | "eliminacao"
  | "privacy" | "terms" | "deletion";

export interface PageSeo {
  path: string;
  lang: "pt-PT" | "en";
  title: string;
  description: string;
  /** Nome curto para o BreadcrumbList. */
  crumb: string;
  /** Ficheiro de origem: o lastmod do sitemap é a data do último commit dele (e dos dados que usa). */
  sources: string[];
  /** Versão na outra língua (hreflang). */
  alternate?: PageKey;
}

export const PAGES: Record<PageKey, PageSeo> = {
  home: {
    path: "/", lang: "pt-PT", crumb: "Início",
    title: "XPLENDOR | Marketing e tecnologia para empresas em Portugal",
    description: "Equipa de marketing e tecnologia em Portugal: redes sociais, campanhas de anúncios, websites e uma plataforma de gestão feita à medida do seu setor.",
    sources: ["app/(pt)/page.tsx", "components/homes"],
  },
  servicos: {
    path: "/servicos/", lang: "pt-PT", crumb: "Serviços",
    title: "Serviços de marketing e tecnologia | XPLENDOR",
    description: "Plataforma de gestão, social media, tráfego pago, websites e consultoria de tecnologia. Conheça os serviços da XPLENDOR e as perguntas frequentes.",
    sources: ["app/(pt)/servicos/page.tsx", "data/faq.ts"],
  },
  plataforma: {
    path: "/plataforma/", lang: "pt-PT", crumb: "Plataforma",
    title: "Plataforma de gestão à medida do setor | XPLENDOR",
    description: "A plataforma XPLENDOR gere o negócio num só lugar: stock, leads, documentos e pós-venda, feita à medida de autocaravanas, carros e restauração.",
    sources: ["app/(pt)/plataforma/page.tsx"],
  },
  contact: {
    path: "/contact/", lang: "pt-PT", crumb: "Contacto",
    title: "Contacto | XPLENDOR",
    description: "Fale com a XPLENDOR sobre marketing, websites ou a plataforma de gestão. Escreva-nos ou ligue-nos e respondemos com rapidez.",
    sources: ["app/(pt)/contact/page.tsx", "components/other-pages/contact"],
  },
  privacidade: {
    path: "/politica-de-privacidade/", lang: "pt-PT", crumb: "Política de Privacidade", alternate: "privacy",
    title: "Política de Privacidade | XPLENDOR",
    description: "Como a XPLENDOR trata os dados pessoais e os dados recebidos da Meta, onde ficam, quanto tempo e como os pode eliminar.",
    sources: ["data/legal/privacy.ts"],
  },
  termos: {
    path: "/termos-e-condicoes/", lang: "pt-PT", crumb: "Termos e Condições", alternate: "terms",
    title: "Termos e Condições | XPLENDOR",
    description: "Condições de utilização do site e da Plataforma XPLENDOR.",
    sources: ["data/legal/terms.ts"],
  },
  eliminacao: {
    path: "/eliminacao-de-dados/", lang: "pt-PT", crumb: "Eliminação de Dados", alternate: "deletion",
    title: "Eliminação de Dados | XPLENDOR",
    description: "Como pedir a eliminação dos dados guardados pela XPLENDOR, incluindo os dados da Meta, e em que prazo.",
    sources: ["data/legal/dataDeletion.ts"],
  },
  privacy: {
    path: "/en/privacy-policy/", lang: "en", crumb: "Privacy Policy", alternate: "privacidade",
    title: "Privacy Policy | XPLENDOR",
    description: "How XPLENDOR handles personal data and the data received from Meta, where it is stored, for how long and how to delete it.",
    sources: ["data/legal/privacy.ts"],
  },
  terms: {
    path: "/en/terms/", lang: "en", crumb: "Terms and Conditions", alternate: "termos",
    title: "Terms and Conditions | XPLENDOR",
    description: "Terms of use of the XPLENDOR website and Platform.",
    sources: ["data/legal/terms.ts"],
  },
  deletion: {
    path: "/en/data-deletion/", lang: "en", crumb: "Data Deletion", alternate: "eliminacao",
    title: "Data Deletion | XPLENDOR",
    description: "How to request deletion of the data stored by XPLENDOR, including Meta data, and within what timeframe.",
    sources: ["data/legal/dataDeletion.ts"],
  },
};

/** Metadados completos de uma página (canonical, hreflang absolutos, Open Graph, Twitter). */
export function pageMetadata(key: PageKey): Metadata {
  const p = PAGES[key];
  const url = absoluteUrl(p.path);
  const alt = p.alternate ? PAGES[p.alternate] : null;
  const languages: Record<string, string> | undefined = alt
    ? {
        [p.lang]: url,
        [alt.lang]: absoluteUrl(alt.path),
        "x-default": absoluteUrl((p.lang === "pt-PT" ? p : alt).path),
      }
    : undefined;

  return {
    title: p.title,
    description: p.description,
    alternates: { canonical: url, ...(languages ? { languages } : {}) },
    openGraph: {
      type: "website",
      siteName: BRAND,
      locale: p.lang === "pt-PT" ? "pt_PT" : "en_GB",
      url,
      title: p.title,
      description: p.description,
      images: [{ url: absoluteUrl(OG_IMAGE.path), width: OG_IMAGE.width, height: OG_IMAGE.height, alt: OG_IMAGE.alt }],
    },
    twitter: { card: "summary_large_image", title: p.title, description: p.description, images: [absoluteUrl(OG_IMAGE.path)] },
  };
}

// ── Dados estruturados (schema.org, JSON-LD) ─────────────────────────────────
// Sem AggregateRating, Review ou qualquer avaliação (não há dados reais).

const ORG_ID = `${SITE_URL}/#organization`;

export function organizationJsonLd() {
  return {
    "@context": "https://schema.org",
    "@type": "Organization",
    "@id": ORG_ID,
    name: BRAND,
    legalName: legal.brandOwnerName,
    url: `${SITE_URL}/`,
    logo: absoluteUrl(LOGO_PATH),
    image: absoluteUrl(OG_IMAGE.path),
    description: IDENTITY,
    slogan: TAGLINE,
    email: legal.email,
    telephone: PHONE,
    vatID: `PT${legal.nif}`,
    areaServed: { "@type": "Country", name: "Portugal" },
    sameAs: socials.map((s) => s.url),
    contactPoint: [{
      "@type": "ContactPoint",
      contactType: "customer service",
      email: legal.email,
      telephone: PHONE,
      areaServed: "PT",
      availableLanguage: ["pt-PT"],
    }],
  };
}

export function websiteJsonLd() {
  return {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "@id": `${SITE_URL}/#website`,
    url: `${SITE_URL}/`,
    name: BRAND,
    inLanguage: "pt-PT",
    publisher: { "@id": ORG_ID },
  };
}

export function servicesJsonLd() {
  return SERVICES.map((s) => ({
    "@context": "https://schema.org",
    "@type": "Service",
    "@id": `${SITE_URL}/servicos/#${s.id}`,
    name: s.name,
    serviceType: s.serviceType,
    description: s.description,
    url: absoluteUrl(`/servicos/#${s.id}`),
    provider: { "@id": ORG_ID },
    areaServed: { "@type": "Country", name: "Portugal" },
  }));
}

export function breadcrumbJsonLd(key: PageKey) {
  const p = PAGES[key];
  const home = p.lang === "pt-PT" ? { name: "Início", url: `${SITE_URL}/` } : { name: "Home", url: `${SITE_URL}/` };
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [home, { name: p.crumb, url: absoluteUrl(p.path) }].map((c, i) => ({
      "@type": "ListItem", position: i + 1, name: c.name, item: c.url,
    })),
  };
}

export function faqJsonLd(items: { question: string; answer: string }[]) {
  return {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: items.map((f) => ({
      "@type": "Question",
      name: f.question,
      acceptedAnswer: { "@type": "Answer", text: f.answer },
    })),
  };
}
