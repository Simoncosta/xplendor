import { useCallback, useEffect, useMemo, useState } from "react";
import { Button, Card, CardBody, Col, Container, Input, Row, Spinner, UncontrolledTooltip } from "reactstrap";
import { useNavigate } from "react-router-dom";
import { ToastContainer } from "react-toastify";
import { useIsMobile } from "../../hooks/useIsMobile";
import PageHeader from "Components/Common/PageHeader";
import { getSupplierCcOverview } from "helpers/laravel_helper";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { SupplierCcCards, SupplierCcRow } from "common/models/supplierCc.model";
import { CcStatusBadge, DIFF_TOOLTIP, eur, fmtDay, fmtInstant, useSupplierCcRefresh } from "./contaCorrente.shared";

/**
 * XPLENDOR — Restauração › Conta Corrente Fornecedores (S2). Saldo REAL de cada fornecedor
 * (sem as Faturas-recibo de compra já pagas, que o PingWin conta como dívida), lado a lado
 * com o saldo do PingWin. Só leitura no PingWin. Positivo = em dívida ao fornecedor.
 */

type QuickFilter = "saldo" | "vencido" | "diferenca" | "problema";
const QUICK: { key: QuickFilter; label: string; test: (r: SupplierCcRow) => boolean }[] = [
    { key: "saldo", label: "Com saldo", test: (r) => r.real_balance_cents !== 0 },
    { key: "vencido", label: "Com vencido", test: (r) => r.overdue_cents > 0 },
    { key: "diferenca", label: "Com diferença", test: (r) => r.difference_cents !== 0 },
    { key: "problema", label: "Com problema", test: (r) => r.status === "failed" || r.status === "not_reconciled" },
];

/** Tudo a zero: sem saldo (real e PingWin), sem diferença, sem vencido, sem docs e sem problema. */
const allZero = (r: SupplierCcRow) =>
    r.real_balance_cents === 0 && (r.pingwin_balance_cents ?? 0) === 0 && r.difference_cents === 0
    && r.overdue_cents === 0 && r.open_docs === 0 && r.status !== "failed" && r.status !== "not_reconciled";

type SortKey = "real_balance_cents" | "pingwin_balance_cents" | "difference_cents" | "overdue_cents" | "open_docs" | "name";

const norm = (s: string | null | undefined) => (s ?? "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();

export default function ContaCorrenteFornecedoresPage() {
    document.title = "Conta Corrente Fornecedores | Restauração | Xplendor";
    const isMobile = useIsMobile();
    const navigate = useNavigate();
    const companyId = useWorkingCompanyId();

    const [rows, setRows] = useState<SupplierCcRow[]>([]);
    const [cards, setCards] = useState<SupplierCcCards | null>(null);
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState("");
    const [quick, setQuick] = useState<QuickFilter[]>([]);
    const [showZero, setShowZero] = useState(false);
    const [sort, setSort] = useState<{ key: SortKey; dir: "asc" | "desc" }>({ key: "real_balance_cents", dir: "desc" });

    const load = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getSupplierCcOverview(companyId);
            setRows(res?.data?.suppliers ?? []);
            setCards(res?.data?.cards ?? null);
        } catch {
            setRows([]);
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => { load(); }, [load]);

    // "Atualizar" numa linha: no fim, recarrega a visão geral (a linha e os cartões).
    const { busy, refresh } = useSupplierCcRefresh(companyId, () => { load(); });

    const visible = useMemo(() => {
        const q = norm(search.trim());
        const list = rows.filter((r) => {
            if (!showZero && allZero(r)) return false;
            if (q && !(norm(r.name).includes(q) || norm(r.code).includes(q) || norm(r.tax_number).includes(q))) return false;
            return quick.every((k) => QUICK.find((f) => f.key === k)!.test(r));
        });
        const dir = sort.dir === "asc" ? 1 : -1;
        return [...list].sort((a, b) => {
            if (sort.key === "name") return dir * norm(a.name).localeCompare(norm(b.name));
            return dir * (((a[sort.key] as number) ?? 0) - ((b[sort.key] as number) ?? 0));
        });
    }, [rows, search, quick, showZero, sort]);

    const toggleQuick = (k: QuickFilter) => setQuick((cur) => (cur.includes(k) ? cur.filter((x) => x !== k) : [...cur, k]));
    const sortBy = (key: SortKey) => setSort((cur) => (cur.key === key ? { key, dir: cur.dir === "asc" ? "desc" : "asc" } : { key, dir: key === "name" ? "asc" : "desc" }));
    const SortTh = ({ k, label, end }: { k: SortKey; label: string; end?: boolean }) => (
        <th className={`${end ? "text-end" : ""} text-nowrap`} role="button" onClick={() => sortBy(k)}>
            {label}{sort.key === k && <i className={`ms-1 ri-arrow-${sort.dir === "asc" ? "up" : "down"}-s-line`} />}
        </th>
    );

    const RefreshBtn = ({ r }: { r: SupplierCcRow }) => (
        <Button size="sm" color="light" title="Atualizar este fornecedor a partir do PingWin" disabled={!!busy[r.supplier_id]}
            onClick={(e) => { e.stopPropagation(); refresh(r.supplier_id); }}>
            {busy[r.supplier_id] ? <Spinner size="sm" style={{ width: 12, height: 12 }} /> : <i className="ri-refresh-line" />}
        </Button>
    );

    const Kpi = ({ label, value, sub, id, tip, tone }: { label: string; value: React.ReactNode; sub?: React.ReactNode; id: string; tip?: string; tone?: string }) => (
        <Card className="mb-3 h-100">
            <CardBody>
                <div className="text-uppercase text-muted fs-11 fw-semibold mb-2" id={id} style={tip ? { cursor: "help" } : undefined}>
                    {label}{tip && <i className="ri-information-line ms-1" />}
                </div>
                {tip && <UncontrolledTooltip target={id}>{tip}</UncontrolledTooltip>}
                <div className={`fs-4 fw-semibold ${tone ?? ""}`}>{value}</div>
                {sub && <div className="text-muted fs-12 mt-1">{sub}</div>}
            </CardBody>
        </Card>
    );

    const go = (r: SupplierCcRow) => navigate(`/restauracao/conta-corrente/${r.supplier_id}`);
    const zeroHidden = rows.filter(allZero).length;

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title="Conta Corrente Fornecedores"
                    breadcrumbs={[{ label: "Restauração" }]}
                    description={<>Saldo real de cada fornecedor, sem as faturas-recibo de compra já pagas que o PingWin conta como dívida. Positivo = em dívida.</>}
                />

                <Row className="g-3 mb-1">
                    <Col xs={12} sm={6} xl={3}><Kpi id="kpi-real" label="Saldo real total (em dívida)" value={eur(cards?.real_balance_cents)} sub={cards ? <>PingWin: {eur(cards.pingwin_balance_cents)}</> : null} /></Col>
                    <Col xs={12} sm={6} xl={3}><Kpi id="kpi-vencido" label="Vencido" value={eur(cards?.overdue_cents)} tone={(cards?.overdue_cents ?? 0) > 0 ? "text-danger" : undefined} /></Col>
                    <Col xs={12} sm={6} xl={3}><Kpi id="kpi-diff" label="Contado a mais pelo PingWin" value={eur(cards?.difference_cents)} tip={DIFF_TOOLTIP} tone="text-warning" /></Col>
                    <Col xs={12} sm={6} xl={3}><Kpi id="kpi-problem" label="Fornecedores com problema" value={cards?.problem_suppliers ?? "—"} sub="Sync falhada ou não reconciliados" tone={(cards?.problem_suppliers ?? 0) > 0 ? "text-danger" : undefined} /></Col>
                </Row>

                <Card className="mb-3">
                    <div className="card-header">
                        <Row className="g-2 align-items-center">
                            <Col md={4}>
                                <Input bsSize="sm" placeholder="Pesquisar por nome, código ou NIF" value={search} onChange={(e) => setSearch(e.target.value)} />
                            </Col>
                            <Col md={8} className="d-flex flex-wrap align-items-center gap-1">
                                {QUICK.map((f) => {
                                    const on = quick.includes(f.key);
                                    return (
                                        <button key={f.key} type="button" onClick={() => toggleQuick(f.key)}
                                            className={`btn btn-sm ${on ? "btn-soft-primary" : "btn-light text-muted"}`} style={{ borderRadius: 999 }}>
                                            {on && <i className="ri-check-line me-1" />}{f.label}
                                        </button>
                                    );
                                })}
                                <div className="form-check ms-2 mb-0">
                                    <input className="form-check-input" type="checkbox" id="cc-show-zero" checked={showZero} onChange={(e) => setShowZero(e.target.checked)} />
                                    <label className="form-check-label fs-12" htmlFor="cc-show-zero">Mostrar os sem movimento{zeroHidden ? ` (${zeroHidden})` : ""}</label>
                                </div>
                                {loading && <Spinner size="sm" className="ms-auto" />}
                            </Col>
                        </Row>
                    </div>

                    {isMobile ? (
                        <div className="p-3 d-flex flex-column gap-2">
                            {!loading && visible.length === 0 ? (
                                <div className="text-center text-muted py-4">Sem fornecedores com estes filtros.</div>
                            ) : visible.map((r) => (
                                <div key={r.supplier_id} role="button" onClick={() => go(r)}
                                    style={{ border: "1px solid var(--vz-border-color)", borderRadius: 12, padding: "12px 14px", background: "var(--vz-card-bg)", cursor: "pointer" }}>
                                    <div className="d-flex align-items-start justify-content-between gap-2">
                                        <div style={{ minWidth: 0 }}>
                                            <div className="fw-semibold text-body text-truncate">{r.name || "—"}</div>
                                            <div className="text-muted fs-12">{r.code || "—"} · NIF {r.tax_number || "—"}</div>
                                        </div>
                                        <CcStatusBadge status={r.status} error={r.last_error} id={`m-st-${r.supplier_id}`} />
                                    </div>
                                    <div className="d-flex justify-content-between align-items-end mt-2">
                                        <div className="fs-12 text-muted">
                                            PingWin {eur(r.pingwin_balance_cents)}{r.overdue_cents > 0 && <> · <span className="text-danger">vencido {eur(r.overdue_cents)}</span></>}
                                        </div>
                                        <span className="fw-semibold">{eur(r.real_balance_cents)}</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="table-responsive">
                            <table className="table table-bordered table-hover align-middle mb-0">
                                <thead className="text-muted table-light">
                                    <tr>
                                        <th>Código</th>
                                        <SortTh k="name" label="Fornecedor" />
                                        <th>NIF</th>
                                        <SortTh k="pingwin_balance_cents" label="Saldo PingWin" end />
                                        <SortTh k="real_balance_cents" label="Saldo real" end />
                                        <SortTh k="difference_cents" label="Diferença" end />
                                        <SortTh k="overdue_cents" label="Vencido" end />
                                        <SortTh k="open_docs" label="Docs por liquidar" end />
                                        <th className="text-nowrap">Próximo vencimento</th>
                                        <th className="text-center">Estado</th>
                                        <th className="text-nowrap">Última sync</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {!loading && visible.length === 0 ? (
                                        <tr><td colSpan={12} className="text-center text-muted py-4">Sem fornecedores com estes filtros.</td></tr>
                                    ) : visible.map((r) => (
                                        <tr key={r.supplier_id} role="button" onClick={() => go(r)} style={{ cursor: "pointer" }}>
                                            <td className="text-nowrap">{r.code || "—"}</td>
                                            <td className="fw-medium">{r.name || "—"}</td>
                                            <td className="text-nowrap">{r.tax_number || "—"}</td>
                                            <td className="text-end text-nowrap text-muted">{eur(r.pingwin_balance_cents)}</td>
                                            <td className="text-end text-nowrap fw-semibold">{eur(r.real_balance_cents)}</td>
                                            <td className={`text-end text-nowrap ${r.difference_cents !== 0 ? "text-warning" : "text-muted"}`}>{eur(r.difference_cents)}</td>
                                            <td className={`text-end text-nowrap ${r.overdue_cents > 0 ? "text-danger" : "text-muted"}`}>{eur(r.overdue_cents)}</td>
                                            <td className="text-end">{r.open_docs}</td>
                                            <td className="text-nowrap">{fmtDay(r.next_due_date)}</td>
                                            <td className="text-center"><CcStatusBadge status={busy[r.supplier_id] ? "syncing" : r.status} error={r.last_error} id={`st-${r.supplier_id}`} /></td>
                                            <td className="text-nowrap fs-12 text-muted">{fmtInstant(r.synced_at)}</td>
                                            <td className="text-end"><RefreshBtn r={r} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </Container>
        </div>
    );
}
