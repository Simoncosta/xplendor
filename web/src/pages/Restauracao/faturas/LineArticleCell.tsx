import { Button, Spinner } from "reactstrap";
import ActionsMenu from "Components/Common/ActionsMenu";
import { OCR_LINK_METHOD_LABEL, OcrLineLink } from "common/models/ocr.model";

/**
 * XPLENDOR — F2b: a célula "Artigo" de uma linha da fatura: ligada (com o método e o estado do
 * código do fornecedor no PingWin), sugerida (aceitar com um clique) ou por ligar (associar ou
 * criar). No máximo uma ação visível; o resto no menu "...".
 *
 * F2d: o código da linha pode ficar como ALTERNATIVO (o PingWin guarda um código por fornecedor:
 * o principal), com "Tornar principal no PingWin" no menu; os códigos do fornecedor no artigo;
 * o selo "código provavelmente mal lido" (EAN/GTIN com o controlo errado); o aviso de IVA
 * (fatura ≠ artigo) com "Usar o da fatura nesta linha" e "Corrigir o IVA do artigo no PingWin";
 * e as sugestões "caixa / unidade do mesmo produto" (GTIN-14 ↔ EAN-13).
 */

type Props = {
    link: OcrLineLink | null;
    saved: boolean;
    busy: boolean;
    onAccept: (articleId: number) => void;
    onAssociate: () => void;
    onCreate: () => void;
    onUnlink: () => void;
    onMakePrincipal?: () => void;
    onVatInfo?: () => void;
    onVatFix?: () => void;
};

const REASON_LABEL: Record<string, { badge: string; title: string }> = {
    gtin_caixa: { badge: "Caixa do mesmo produto", title: "O código da linha (GTIN-14) é a caixa de um produto cujo EAN-13 já está associado a este artigo." },
    gtin_unidade: { badge: "Unidade do mesmo produto", title: "O código da linha (EAN-13) é a unidade de um produto cuja caixa (GTIN-14) já está associada a este artigo." },
};

const pct = (n: number) => `${Math.round(n * 100)}%`;
const manual = (m: string | null) => m === "manual" || m === "sugestao" || m === "criado";

function SupplierCodeStatus({ link }: { link: OcrLineLink }) {
    switch (link.supplier_code_status) {
        case "pendente":
            return <span className="fs-12 text-muted"><Spinner size="sm" style={{ width: 10, height: 10 }} className="me-1" />A gravar o código do fornecedor no PingWin…</span>;
        case "ok":
            return <span className="fs-12 text-success"><i className="ri-check-line me-1" />Código do fornecedor no artigo</span>;
        case "alternativo":
            return (
                <span className="fs-12 text-muted" data-testid="code-alternative">
                    <i className="ri-git-branch-line me-1" />Código alternativo na XPLENDOR{link.principal_code ? <> · principal no PingWin <span className="font-monospace">{link.principal_code}</span></> : null}
                </span>
            );
        case "erro":
            return <span className="fs-12 text-danger" title={link.supplier_code_error ?? ""}><i className="ri-error-warning-line me-1" />{link.supplier_code_error ?? "Código do fornecedor não gravado"}</span>;
        default:
            return null;
    }
}

/** "Código provavelmente mal lido": formato EAN/GTIN com o dígito de controlo errado. */
function CodeCheck({ link }: { link: OcrLineLink }) {
    const c = link.supplier_code_check;
    if (!c || c.valid) return null;
    return (
        <span className="badge bg-warning-subtle text-warning me-1" data-testid="code-misread"
            title={`${c.kind} com o dígito de controlo errado: não entra no mapa sozinho nem vai para o PingWin (só por associação à mão).`}>
            <i className="ri-error-warning-line me-1" />Código provavelmente mal lido
        </span>
    );
}

/** Os códigos deste fornecedor no artigo: o principal (PingWin) e os alternativos (XPLENDOR). */
function SupplierCodes({ link }: { link: OcrLineLink }) {
    const codes = link.supplier_codes ?? [];
    if (codes.length < 2) return null;
    return (
        <div className="fs-12 text-muted mt-1" data-testid="supplier-codes">
            {codes.map((c) => (
                <span key={c.code} className="me-2 text-nowrap" title={`${c.uses} ${c.uses === 1 ? "uso" : "usos"}${c.check && !c.check.valid ? " · controlo errado" : ""}`}>
                    <span className="font-monospace">{c.code}</span>{" "}
                    {c.principal ? <span className="badge bg-primary-subtle text-primary">principal</span> : <span className="badge bg-light text-body">alternativo</span>}
                    {c.suggested_principal && <span className="badge bg-info-subtle text-info ms-1" title="O mais usado: pode torná-lo principal no PingWin">mais usado</span>}
                </span>
            ))}
        </div>
    );
}

/** IVA da fatura ≠ IVA do artigo (e o estado de "Corrigir o IVA do artigo"). */
function VatCheck({ link }: { link: OcrLineLink }) {
    const w = link.vat_write;
    if (w?.status === "a_editar") {
        return <div className="fs-12 text-muted"><Spinner size="sm" style={{ width: 10, height: 10 }} className="me-1" />A corrigir o IVA do artigo no PingWin…</div>;
    }
    if (w?.status === "erro" && link.vat_check) {
        return <div className="fs-12 text-danger" title={w.error ?? ""}><i className="ri-error-warning-line me-1" />O IVA do artigo não foi corrigido{w.error ? `: ${w.error}` : "."}</div>;
    }
    if (w?.status === "ok" && !link.vat_check) {
        return <div className="fs-12 text-success"><i className="ri-check-line me-1" />IVA do artigo corrigido no PingWin</div>;
    }
    if (!link.vat_check) return null;
    return (
        <span className="badge bg-warning-subtle text-warning" data-testid="vat-diff"
            title="Ao lançar, a linha vai com o IVA da fatura. Pode também corrigir o IVA do artigo no PingWin (menu ...).">
            IVA da fatura {link.vat_check.invoice_rate}% ≠ artigo {link.vat_check.article_rate}%
        </span>
    );
}

export default function LineArticleCell({ link, saved, busy, onAccept, onAssociate, onCreate, onUnlink, onMakePrincipal, onVatInfo, onVatFix }: Props) {
    if (!saved || !link) {
        return <span className="fs-12 text-muted">Guarde a fatura para ligar esta linha.</span>;
    }

    // Despesas gerais: a linha a 0,00 € (por exemplo o código de seguimento) não conta nem precisa de artigo.
    if (link.expense_ignored) {
        return <span className="fs-12 text-muted" data-testid="line-expense-ignored"><i className="ri-subtract-line me-1" />A 0,00 €: não conta (despesa geral).</span>;
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
                    <CodeCheck link={link} />
                    <SupplierCodeStatus link={link} />
                    <SupplierCodes link={link} />
                    <VatCheck link={link} />
                </div>
                <ActionsMenu size="sm" label="Mais ações do artigo" items={[
                    { label: "Tornar principal no PingWin", icon: "ri-star-line", onClick: () => onMakePrincipal?.(),
                        hidden: link.supplier_code_status !== "alternativo" || !onMakePrincipal || !!(link.supplier_code_check && !link.supplier_code_check.valid) },
                    { label: "Usar o IVA da fatura nesta linha", icon: "ri-percent-line", onClick: () => onVatInfo?.(), hidden: !link.vat_check || !onVatInfo },
                    { label: "Corrigir o IVA do artigo no PingWin", icon: "ri-edit-line", onClick: () => onVatFix?.(),
                        hidden: !link.vat_check || !onVatFix, disabledReason: link.vat_write?.status === "a_editar" ? "Já está a ser corrigido." : null },
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
                        {top.reason && REASON_LABEL[top.reason]
                            ? <span className="badge bg-info-subtle text-info me-1" title={REASON_LABEL[top.reason].title} data-testid="gtin-suggestion">{REASON_LABEL[top.reason].badge}</span>
                            : <span className="badge bg-info-subtle text-info me-1" title="Descrição parecida: confirme">Sugestão {pct(top.score)}</span>}
                        <span className="font-monospace me-1">{top.code}</span>{top.description}
                    </div>
                    {top.reason && (
                        <div className="fs-12 text-muted">Pelo código {top.via_code}. Confirme o artigo e, ao lançar, a unidade e a quantidade (caixa ≠ unidade).</div>
                    )}
                    <CodeCheck link={link} />
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
            <span><span className="badge bg-warning-subtle text-warning me-1">Por ligar</span><CodeCheck link={link} /></span>
            <div className="d-flex gap-1">
                <Button size="sm" color="outline-primary" onClick={onAssociate} disabled={busy}><i className="ri-search-line me-1" />Associar</Button>
                <ActionsMenu size="sm" label="Mais ações do artigo" items={[{ label: "Criar artigo", icon: "ri-add-line", onClick: onCreate }]} disabled={busy} />
            </div>
        </div>
    );
}
