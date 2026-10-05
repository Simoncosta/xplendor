import Link from "next/link";
import Footer2 from "@/components/footers/Footer2";

/**
 * XPLENDOR: página legal (privacidade, termos, eliminação de dados), em PT e EN.
 * Mesmo cabeçalho das outras páginas do site (mxd-section-inner-headline) e uma
 * área de leitura com índice. O conteúdo vive em data/legal/*.ts.
 */

export type LegalBlock = string | { h3: string } | { list: string[] };

export interface LegalSection {
  id: string;
  title: string;
  blocks: LegalBlock[];
}

export interface LegalContent {
  eyebrow: string;
  title: string;
  intro: string;
  updatedLabel: string;
  updated: string;
  tocLabel: string;
  alternate: { href: string; label: string };
  sections: LegalSection[];
}

/** Liga os caminhos internos (/eliminacao-de-dados/), os sites (www.cnpd.pt) e os emails citados no texto. */
const LINK_RE = /(\/(?:en\/)?[a-z-]+\/|www\.[a-z.]+\.pt|[\w.+-]+@[\w-]+\.[\w.]+[a-z])/g;

function linkify(text: string) {
  return text.split(LINK_RE).map((part, i) => {
    if (i % 2 === 0) return part;
    if (part.startsWith("/")) return <Link key={i} href={part}>{part}</Link>;
    if (part.includes("@")) return <a key={i} href={`mailto:${part}`}>{part}</a>;
    return (
      <a key={i} href={`https://${part}`} target="_blank" rel="noopener noreferrer">
        {part}
      </a>
    );
  });
}

export default function LegalPage({ content }: { content: LegalContent }) {
  return (
    <>
      <main id="mxd-page-content" className="mxd-page-content inner-page-content">
        {/* Cabeçalho */}
        <div className="mxd-section mxd-section-inner-headline padding-s-headline-pre-grid">
          <div className="mxd-container grid-container">
            <div className="mxd-block loading-wrap">
              <div className="container-fluid px-0">
                <div className="row gx-0">
                  <div className="col-12 col-xl-2 mxd-grid-item no-margin">
                    <div className="mxd-block__name name-inner-headline loading__item">
                      <p className="mxd-point-subtitle">
                        <span>{content.eyebrow}</span>
                      </p>
                    </div>
                  </div>
                  <div className="col-12 col-xl-10 mxd-grid-item no-margin">
                    <div className="mxd-block__content">
                      <div className="mxd-block__inner-headline">
                        <h1 className="inner-headline__title loading__item">{content.title}</h1>
                        <p className="inner-headline__text t-large t-bright loading__item">{content.intro}</p>
                        <p className="xp-legal__meta loading__item">
                          {content.updatedLabel}: {content.updated}
                          <span className="xp-legal__sep" aria-hidden="true">·</span>
                          <Link href={content.alternate.href} hrefLang={content.alternate.href.startsWith("/en/") ? "en" : "pt"}>
                            {content.alternate.label}
                          </Link>
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Conteúdo */}
        <div className="mxd-section padding-default">
          <div className="mxd-container grid-container">
            <div className="mxd-block">
              <div className="xp-legal">
                <nav className="xp-legal__toc" aria-label={content.tocLabel}>
                  <p className="xp-legal__toc-title">{content.tocLabel}</p>
                  <ol>
                    {content.sections.map((s) => (
                      <li key={s.id}>
                        <a href={`#${s.id}`}>{s.title}</a>
                      </li>
                    ))}
                  </ol>
                </nav>
                <article className="xp-legal__body">
                  {content.sections.map((s) => (
                    <section key={s.id} id={s.id} className="xp-legal__section">
                      <h2>{s.title}</h2>
                      {s.blocks.map((b, i) =>
                        typeof b === "string" ? (
                          <p key={i}>{linkify(b)}</p>
                        ) : "h3" in b ? (
                          <h3 key={i}>{b.h3}</h3>
                        ) : (
                          <ul key={i}>
                            {b.list.map((item, j) => (
                              <li key={j}>{linkify(item)}</li>
                            ))}
                          </ul>
                        )
                      )}
                    </section>
                  ))}
                </article>
              </div>
            </div>
          </div>
        </div>
      </main>
      <Footer2 />
    </>
  );
}
