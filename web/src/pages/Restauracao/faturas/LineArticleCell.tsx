import { Button, Spinner } from "reactstrap";
import ActionsMenu from "Components/Common/ActionsMenu";
import { OCR_LINK_METHOD_LABEL, OcrLineLink } from "common/models/ocr.model";

/**
 * XPLENDOR — F2b: a célula "Artigo" de uma linha da fatura: ligada (com o método e o estado do
 * código do fornecedor no PingWin), sugerida (aceitar com um clique) ou por ligar (associar ou
 * criar). No máximo uma ação visível; o resto no menu "...".
 */

type Props = {
    link: OcrLineLink | null;
    saved: boolean;
    busy: boolean;
    onAccept: (articleId: number) => void;
    onAssociate: () => void;
    onCreate: () => void;
    onUnlink: () => void;
};

const pct = (n: number) => `${Math.round(n * 100)}%`;
const manual = (m: string | null) => m === "manual" || m === "sugestao" || m === "criado";

function SupplierCodeStatus({ link }: { link: OcrLineLink }) {
    switch (link.supplier_code_status) {
        case "pendente":
            return <span className="fs-12 text-muted"><Spinner size="sm" style={{ width: 10, height: 10 }} className="me-1" />A gravar o código do fornecedor no PingWin…</span>;
        case "ok":
            return <span className="fs-12 text-success"><i className="ri-check-line me-1" />Código do fornecedor no artigo</span>;
        case "conflito":
        case "erro":
            return <span className="fs-12 text-danger" title={link.supplier_code_error ?? ""}><i className="ri-error-warning-line me-1" />{link.supplier_code_error ?? "Código do fornecedor não gravado"}</span>;
        default:
            return null;
    }
}

export default function LineArticleCell({ link, saved, busy, onAccept, onAssociate, onCreate, onUnlink }: Props) {
    if (!saved || !link) {
        return <span className="fs-12 text-muted">Guarde a fatura para ligar esta linha.</span>;
    }

    if (link.creating && link.creating.status !== "ok") {
        return link.creating.status === "erro"
            ? <span className="fs-12 text-danger" title={link.creating.error ?? ""}><i className="ri-error-warning-line me-1" />Não foi possível criar o artigo{link.creating.error ? `: ${link.creating.error}` : "."}</span>
            : <span className="fs-12 text-muted"><Spinner size="sm" style={{ width: 10, height: 10 }} className="me-1" />A criar o artigo no PingWin…</span>;
    }

    if (link.link_state === "ligada" && link.article) {
        return (
            <div className="d-flex align-items-start justify-content-between gap-2" data-testid="line-article-linked">
                <div style={{ minWidth: 0 }}>
                    <div className="fs-13 text-truncate" title={link.article.description ?? ""}>
                        <span className="font-monospace me-1">{link.article.code}</span>{link.article.description}
                    </div>
                    <span className="badge bg-success-subtle text-success me-1">{link.link_method ? OCR_LINK_METHOD_LABEL[link.link_method] : "Ligada"}</span>
                    <SupplierCodeStatus link={link} />
                </div>
                <ActionsMenu size="sm" label="Mais ações do artigo" items={[
                    { label: "Associar a outro artigo", icon: "ri-search-line", onClick: onAssociate },
                    { label: "Desfazer a ligação", icon: "ri-link-unlink", onClick: onUnlink, hidden: !manual(link.link_method) },
                ]} disabled={busy} />
            </div>
        );
    }

    if (link.link_state === "sugerida" && link.suggestions.length > 0) {
        const [top, ...others] = link.suggestions;
        return (
            <div className="d-flex align-items-start justify-content-between gap-2" data-testid="line-article-suggested">
                <div style={{ minWidth: 0 }}>
                    <div className="fs-13 text-truncate" title={top.description ?? ""}>
                        <span className="badge bg-info-subtle text-info me-1" title="Descrição parecida: confirme">Sugestão {pct(top.score)}</span>
                        <span className="font-monospace me-1">{top.code}</span>{top.description}
                    </div>
                </div>
                <div className="d-flex gap-1 flex-shrink-0">
                    <Button size="sm" color="outline-primary" onClick={() => onAccept(top.id)} disabled={busy}>Aceitar</Button>
                    <ActionsMenu size="sm" label="Mais ações do artigo" items={[
                        ...others.map((o) => ({ label: `Aceitar ${o.code ?? ""} ${o.description ?? ""} (${pct(o.score)})`, icon: "ri-check-line", onClick: () => onAccept(o.id) })),
                        { label: "Associar a outro artigo", icon: "ri-search-line", onClick: onAssociate },
                        { label: "Criar artigo", icon: "ri-add-line", onClick: onCreate },
                    ]} disabled={busy} />
                </div>
            </div>
        );
    }

    return (
        <div className="d-flex align-items-center justify-content-between gap-2" data-testid="line-article-unlinked">
            <span className="badge bg-warning-subtle text-warning">Por ligar</span>
            <div className="d-flex gap-1">
                <Button size="sm" color="outline-primary" onClick={onAssociate} disabled={busy}><i className="ri-search-line me-1" />Associar</Button>
                <ActionsMenu size="sm" label="Mais ações do artigo" items={[{ label: "Criar artigo", icon: "ri-add-line", onClick: onCreate }]} disabled={busy} />
            </div>
        </div>
    );
}
