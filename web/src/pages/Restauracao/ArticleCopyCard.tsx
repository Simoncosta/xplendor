import { Fragment } from "react";
import { Alert, Button, Spinner } from "reactstrap";
import PageCard from "Components/Common/PageCard";
import { ArticleUnitChange } from "common/models/unitGuide.model";
import UnitChangesAlert from "./units/UnitChangesAlert";

/**
 * A cópia local do artigo (sem esperar pelo PingWin): mostra-se logo ao abrir, com a hora dos dados,
 * enquanto a leitura ao vivo não chega; se o PingWin não responder, fica com o aviso.
 */
export type ArticleCopyUnit = { label: string; id: string | null; name: string | null };
export type ArticleCopy = {
    catalog_item_id: number; pingwin_id: string; code: string | null; description: string | null; family: string | null;
    product_status: string | null; is_active: boolean; saleprice_cents: number | null; purchaseprice_cents: number | null;
    units: Record<"base" | "purchase" | "sale" | "stock", ArticleCopyUnit>; read_at: string | null; live_read_at: string | null;
    unit_changes?: ArticleUnitChange[];
};
export type ArticleChange = { field: string; label: string; from: string | null; to: string | null };

export const fmtWhen = (iso: string | null) => {
    if (!iso) return "data desconhecida";
    const d = new Date(iso);
    const day = d.toLocaleDateString("pt-PT", { timeZone: "Europe/Lisbon", day: "2-digit", month: "2-digit" });
    const time = d.toLocaleTimeString("pt-PT", { timeZone: "Europe/Lisbon", hour: "2-digit", minute: "2-digit" });
    return `${day} às ${time}`;
};
const eur = (c: number | null) => (c === null ? "—" : (c / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR" }));

export default function ArticleCopyCard({ copy, error, onRetry }: { copy: ArticleCopy; error: string | null; onRetry?: () => void }) {
    return (
        <PageCard title={copy.description ?? "Artigo"} flush={false} data-testid="article-copy"
            status={error ? undefined : <span className="text-muted fs-12"><Spinner size="sm" className="me-1" />Dados de {fmtWhen(copy.read_at)}, a atualizar…</span>}>
            {error && (
                <Alert color="warning" className="py-2 fs-13" data-testid="article-copy-error">
                    <i className="ri-error-warning-line me-1" />{error} Estes são os dados guardados de {fmtWhen(copy.read_at)}.
                    {onRetry && <Button size="sm" color="link" className="py-0" onClick={onRetry}>Tentar de novo</Button>}
                </Alert>
            )}
            <UnitChangesAlert changes={copy.unit_changes ?? []} />
            <dl className="row mb-0 fs-13">
                <dt className="col-5 col-md-3">Código</dt><dd className="col-7 col-md-9">{copy.code ?? "—"}</dd>
                <dt className="col-5 col-md-3">Família</dt><dd className="col-7 col-md-9">{copy.family ?? "—"}</dd>
                <dt className="col-5 col-md-3">Estado</dt><dd className="col-7 col-md-9">{copy.product_status ?? (copy.is_active ? "Ativo" : "Anulado")}</dd>
                {(["base", "purchase", "sale", "stock"] as const).map((k) => (
                    <Fragment key={k}>
                        <dt className="col-5 col-md-3">{copy.units[k].label}</dt><dd className="col-7 col-md-9" data-testid={`copy-unit-${k}`}>{copy.units[k].name ?? "—"}</dd>
                    </Fragment>
                ))}
                <dt className="col-5 col-md-3">Preço de venda</dt><dd className="col-7 col-md-9">{eur(copy.saleprice_cents)}</dd>
                <dt className="col-5 col-md-3">Preço de compra</dt><dd className="col-7 col-md-9">{eur(copy.purchaseprice_cents)}</dd>
            </dl>
        </PageCard>
    );
}

/** O que mudou face à cópia, quando a leitura ao vivo chega. */
export function ArticleChanges({ changes }: { changes: ArticleChange[] }) {
    if (changes.length === 0) return null;
    return (
        <Alert color="info" className="py-2 fs-13" data-testid="article-changes">
            <i className="ri-refresh-line me-1" /><strong>Mudou no PingWin desde a última leitura:</strong>{" "}
            {changes.map((c) => `${c.label}: ${c.from ?? "—"} → ${c.to ?? "—"}`).join(" · ")}. A cópia da XPLENDOR ficou atualizada.
        </Alert>
    );
}
