import { COMPANY } from "@/data/legal/company";

/**
 * Ligações legais do rodapé (todas as páginas) e linha da marca, em PT e EN. O Livro de
 * Reclamações abre num separador novo. Fonte única: o rodapé partilhado e a 404 usam
 * esta lista.
 */
export type SiteLocale = "pt" | "en";

export const LIVRO_RECLAMACOES_URL = "https://www.livroreclamacoes.pt/inicio/";

export const LEGAL_LINKS: Record<SiteLocale, { label: string; href: string; external?: boolean }[]> = {
  pt: [
    { label: "Política de Privacidade", href: "/politica-de-privacidade/" },
    { label: "Termos e Condições", href: "/termos-e-condicoes/" },
    { label: "Livro de Reclamações", href: LIVRO_RECLAMACOES_URL, external: true },
  ],
  en: [
    { label: "Privacy Policy", href: "/en/privacy-policy/" },
    { label: "Terms and Conditions", href: "/en/terms/" },
    { label: "Complaints Book", href: LIVRO_RECLAMACOES_URL, external: true },
  ],
};

export const BRAND_LINE: Record<SiteLocale, string> = {
  pt: `XPLENDOR é uma marca de ${COMPANY.brandOwnerName}.`,
  en: `XPLENDOR is a brand of ${COMPANY.brandOwnerName}.`,
};

/** Linha simples de ligações (usada na 404, onde não há o rodapé completo). */
export default function LegalLinks({ locale = "pt", className = "" }: { locale?: SiteLocale; className?: string }) {
  return (
    <nav className={`xp-legal-links ${className}`} aria-label={locale === "en" ? "Legal" : "Informação legal"}>
      {LEGAL_LINKS[locale].map((l) => (
        <a key={l.href} href={l.href} {...(l.external ? { target: "_blank", rel: "noopener noreferrer" } : {})}>
          {l.label}
        </a>
      ))}
    </nav>
  );
}
