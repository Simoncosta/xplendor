import { PREVIEW } from "@/content/textos";

/** A faixa da pré-visualização (link assinado da XPLENDOR): sempre visível no topo. */
export function PreviewBanner() {
    return (
        <div role="status" className="bg-amber text-navy">
            <div className="container-site flex h-11 items-center justify-between gap-6 text-sm">
                <p className="truncate"><strong className="font-semibold">{PREVIEW.faixa}.</strong> <span className="hidden md:inline">{PREVIEW.texto}</span></p>
                {/* Uma rota do servidor que apaga o cookie: navegação completa, não do router. */}
                <a href="/pre-visualizar/sair" className="shrink-0 font-semibold underline underline-offset-4">{PREVIEW.sair}</a>
            </div>
        </div>
    );
}
