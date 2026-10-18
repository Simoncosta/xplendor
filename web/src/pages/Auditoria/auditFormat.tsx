import HiddenMoney from "Components/Common/HiddenMoney";
import InfoTip from "Components/Common/InfoTip";
import { AuditEvidence, AuditEvidenceFormat, AuditSeverity, AuditState } from "common/models/audit.model";

/** XPLENDOR — Auditoria: urgência, estado, euros e "O que vimos" de um achado (factos e tabela). */

export const SEVERITY: Record<AuditSeverity, { label: string; cls: string; icon: string; color: string }> = {
    alta: { label: "Urgente", cls: "bg-danger-subtle text-danger", icon: "ri-error-warning-fill", color: "danger" },
    media: { label: "Importante", cls: "bg-warning-subtle text-warning", icon: "ri-alert-line", color: "warning" },
    baixa: { label: "A rever", cls: "bg-info-subtle text-info", icon: "ri-information-line", color: "info" },
    info: { label: "Informação", cls: "bg-secondary-subtle text-secondary", icon: "ri-information-line", color: "secondary" },
};

export const STATE: Record<AuditState, { label: string; cls: string }> = {
    aberto: { label: "Aberto", cls: "bg-danger-subtle text-danger" },
    aceite: { label: "Aceite", cls: "bg-primary-subtle text-primary" },
    ignorado: { label: "Ignorado", cls: "bg-secondary-subtle text-secondary" },
    corrigido: { label: "Marcado como corrigido", cls: "bg-info-subtle text-info" },
    resolvido: { label: "Resolvido", cls: "bg-success-subtle text-success" },
};

export const ENTITY_LABEL: Record<string, string> = {
    artigo: "Artigo", linha_compra: "Linha de compra", unidade: "Unidade", artigo_unidade: "Artigo e unidade de compra",
    grupo_artigos: "Grupo de artigos", ficha: "Ficha técnica", documento: "Documento", viatura: "Viatura",
    evento_tracking: "Evento do site", recusa_tracking: "Eventos recusados", fornecedor: "Fornecedor", familia: "Família", armazem: "Armazém",
    funcionario: "Funcionário",
};

export function SeverityBadge({ severity }: { severity: AuditSeverity }) {
    const s = SEVERITY[severity] ?? SEVERITY.info;
    return <span className={`badge ${s.cls}`}><i className={`${s.icon} me-1`} aria-hidden />{s.label}</span>;
}

export function AuditStateBadge({ state }: { state: AuditState }) {
    const s = STATE[state] ?? STATE.aberto;
    return <span className={`badge ${s.cls}`}>{s.label}</span>;
}

/** Um número em português, com separador de milhares sempre (1 227,36). */
export const fmtNum = (v: number, decimals = 2) => {
    const [int, frac] = Math.abs(v).toFixed(decimals).split(".");
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, " ");
    return `${v < 0 ? "-" : ""}${grouped}${frac ? `,${frac}` : ""}`;
};

export const fmtEur = (v: number | null | undefined, decimals = 2) =>
    v === null || v === undefined ? "—" : `${fmtNum(v, decimals)} €`;

/** Um preço por unidade: 2 casas; 4 só abaixo de 0,10 €. */
export const fmtUnitPrice = (v: number | null | undefined) =>
    v === null || v === undefined ? "—" : fmtEur(v, Math.abs(v) < 0.1 && v !== 0 ? 4 : 2);

export const fmtDate = (d?: string | null) =>
    d ? new Date(`${d.slice(0, 10)}T00:00:00`).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "—";

export const fmtWhen = (d?: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—";

/** Euros em cêntimos: o valor, ou desfocado para quem não vê as Finanças. */
export function Cents({ value, hidden, seed = 0 }: { value: number | null | undefined; hidden: boolean; seed?: number }) {
    if (hidden) return <HiddenMoney seed={seed} />;
    return <>{value === null || value === undefined ? "—" : fmtEur(value / 100)}</>;
}

const MONEY: AuditEvidenceFormat[] = ["eur", "eur4", "cents"];

function formatValue(v: any, format?: AuditEvidenceFormat): string {
    if (v === null || v === undefined || v === "") return "—";
    switch (format) {
        case "eur": return fmtEur(Number(v));
        case "eur4": return fmtUnitPrice(Number(v));
        case "cents": return fmtEur(Number(v) / 100);
        case "pct": return `${Number(v).toLocaleString("pt-PT")}%`;
        case "qty": return Number.isInteger(Number(v)) ? fmtNum(Number(v), 0) : Number(v).toLocaleString("pt-PT", { maximumFractionDigits: 3 });
        case "date": return fmtDate(String(v));
        default: return typeof v === "number" ? (Number.isInteger(v) ? fmtNum(v, 0) : v.toLocaleString("pt-PT")) : String(v);
    }
}

/** Os pormenores de "O que vimos": os factos (rótulo e valor) e a tabela; os euros escondidos ficam desfocados. */
export function EvidenceView({ evidence, hidden }: { evidence: AuditEvidence; hidden: boolean }) {
    const facts = evidence.facts ?? [];
    const table = evidence.table;
    const hiddenCols = new Set(table?.hidden_columns ?? []);
    return (
        <div data-testid="audit-evidence">
            {facts.length > 0 && (
                <dl className="row mb-2 fs-13">
                    {facts.map((f, i) => (
                        <div key={i} className="col-12 col-sm-6 d-flex gap-2 py-1 border-bottom border-light-subtle">
                            <dt className="text-muted fw-normal mb-0" style={{ minWidth: 150 }}>{f.label}{f.tip && <InfoTip className="ms-1" text={f.tip} label={`Sobre: ${f.label}`} />}</dt>
                            <dd className="mb-0 fw-medium">{f.hidden || (hidden && f.format && MONEY.includes(f.format)) ? <HiddenMoney seed={i} /> : formatValue(f.value, f.format)}</dd>
                        </div>
                    ))}
                </dl>
            )}
            {table && table.rows.length > 0 && (
                <>
                    {table.title && <div className="fw-semibold fs-13 mt-3 mb-1">{table.title}</div>}
                    <div className="table-responsive">
                        <table className="table table-sm align-middle mb-0 fs-13">
                            <thead className="table-light">
                                <tr>{table.columns.map((c) => <th key={c.key} className={c.align === "end" ? "text-end" : undefined}>{c.label}</th>)}</tr>
                            </thead>
                            <tbody>
                                {table.rows.map((r, i) => (
                                    <tr key={i}>
                                        {table.columns.map((c) => (
                                            <td key={c.key} className={`${c.align === "end" ? "text-end text-nowrap" : ""}`}>
                                                {c.key === "description" && r.main ? <i className="ri-star-fill text-warning me-1" title="Artigo principal" aria-label="Artigo principal" /> : null}
                                                {(hiddenCols.has(c.key) || (hidden && c.format && MONEY.includes(c.format))) ? <HiddenMoney seed={i} /> : formatValue(r[c.key], c.format)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
            {evidence.note && <p className="text-muted fs-12 mt-2 mb-0">{evidence.note}</p>}
        </div>
    );
}
