import { Badge } from "reactstrap";
import type { PublishItem } from "common/models/consultantSite.model";

/**
 * Site do consultor: o estado de um anúncio no site (Rascunho, Publicado ou "Faltam: …") e a
 * lista do que falta, em que cada item leva ao campo em falta, no mesmo modal.
 */
export default function SitePublishPanel({ published, items, onGo }: { published: boolean; items: PublishItem[]; onGo: (field: string) => void }) {
    const ready = items.length === 0;
    return (
        <div className="border rounded p-3 mb-3" data-testid="site-publish-panel">
            <div className="d-flex align-items-center gap-2 flex-wrap">
                <span className="fw-medium">Estado no site:</span>
                {published ? <Badge color="success-subtle" className="text-success fw-normal">Publicado</Badge>
                    : ready ? <Badge color="primary-subtle" className="text-primary fw-normal">Rascunho, pronto a publicar</Badge>
                    : <Badge color="warning-subtle" className="text-warning fw-normal text-wrap text-start lh-base" data-testid="site-missing-summary">Faltam: {items.map((i) => i.label).join(", ")}</Badge>}
            </div>
            {!ready && (
                <ul className="list-unstyled mb-0 mt-2" data-testid="publish-missing">
                    {items.map((i) => (
                        <li key={i.field} className="d-flex align-items-start gap-2 fs-13 py-1">
                            <i className="ri-error-warning-line text-warning mt-1" aria-hidden="true" />
                            <span className="flex-grow-1">{i.message}</span>
                            <button type="button" className="btn btn-link btn-sm p-0 text-nowrap" onClick={() => onGo(i.field)} data-testid={`go-${i.field}`}>Ir para o campo</button>
                        </li>
                    ))}
                </ul>
            )}
            {published && !ready && <p className="text-muted fs-12 mb-0 mt-2">Se gravar sem estes dados, o anúncio sai do site até os completar.</p>}
        </div>
    );
}

/** Leva ao campo: muda de separador, foca o campo e assinala-o por um momento. */
export function focusField(id: string) {
    window.setTimeout(() => {
        const el = document.getElementById(id) ?? document.querySelector<HTMLElement>(`[aria-label="${id}"]`);
        if (!el) return;
        el.scrollIntoView?.({ block: "center", behavior: "smooth" });
        (el.querySelector?.("input,select,textarea,button") as HTMLElement | null ?? el).focus?.();
        el.classList.add("field-attention");
        window.setTimeout(() => el.classList.remove("field-attention"), 2200);
    }, 60);
}
