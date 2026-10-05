import "../../public/css/styles.css";
import ClientLayout from "@/components/layout/ClientLayout";
import JsonLd from "@/components/seo/JsonLd";
import { organizationJsonLd, websiteJsonLd } from "@/data/seo";

/**
 * Documento base partilhado pelos layouts raiz PT e EN (cada um com o seu lang).
 * Inclui a Organization e o WebSite em JSON-LD em todas as páginas.
 */
const setColorSchemeScript = `
(function() {
  try {
    var scheme = localStorage.getItem('color-scheme') || 'light';
    document.documentElement.setAttribute('color-scheme', scheme);
  } catch(e) {}
})();
`;

// Sem JavaScript, o texto de topo (que as animações de entrada revelam) fica visível.
const noScriptStyle = ".loading__item,.loading__fade{opacity:1!important}";

export default function RootDocument({ lang, children }: { lang: "pt-PT" | "en"; children: React.ReactNode }) {
  return (
    <html suppressHydrationWarning lang={lang} className="no-touch">
      <head>
        <script dangerouslySetInnerHTML={{ __html: setColorSchemeScript }} />
        <noscript>
          <style dangerouslySetInnerHTML={{ __html: noScriptStyle }} />
        </noscript>
        <JsonLd data={[organizationJsonLd(), websiteJsonLd()]} />
      </head>
      <body>
        <ClientLayout>{children}</ClientLayout>
      </body>
    </html>
  );
}
