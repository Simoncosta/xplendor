import { useCallback, useEffect, useMemo, useState } from "react";
import { Button, Card, CardBody, Col, Container, Row, Spinner, UncontrolledTooltip } from "reactstrap";
import { useNavigate } from "react-router-dom";
import { ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import { getSupplierCcOverview } from "helpers/laravel_helper";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { SupplierCcCards, SupplierCcRow } from "common/models/supplierCc.model";
import { CcStatusBadge, DIFF_TOOLTIP, eur, fmtDay, fmtInstant, useSupplierCcRefresh } from "./contaCorrente.shared";

/**
 * XPLENDOR — Restauração › Conta Corrente Fornecedores (S2). Saldo REAL de cada fornecedor
 * (sem as Faturas-recibo de compra já pagas, que o PingWin conta como dívida), lado a lado
 * com o saldo do PingWin. Só leitura no PingWin. Positivo = em dívida ao fornecedor.
 *
 * UI-2a: a tabela passa a PageCard + DataTable (ordenação, pesquisa e paginação no browser);
 * os filtros rápidos e "Mostrar os sem movimento" ficam na zona de filtros do cartão.
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

const norm = (s: string | null | undefined) => (s ?? "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();

export default function ContaCorrenteFornecedoresPage() {
    document.title = "Conta Corrente Fornecedores | Restauração | Xplendor";
    const navigate = useNavigate();
    const companyId = useWorkingCompanyId();

    const [rows, setRows] = useState<SupplierCcRow[]>([]);
    const [cards, setCards] = useState<SupplierCcCards | null>(null);
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState("");
    const [quick, setQuick] = useState<QuickFilter[]>([]);
    const [showZero, setShowZero] = useState(false);

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

    // Filtros (rápidos e "sem movimento") aqui; a pesquisa (nome, código, NIF) também, como antes.
    const visible = useMemo(() => {
        const q = norm(search.trim());
        return rows.filter((r) => {
            if (!showZero && allZero(r)) return false;
            if (q && !(norm(r.name).includes(q) || norm(r.code).includes(q) || norm(r.tax_number).includes(q))) return false;
            return quick.every((k) => QUICK.find((f) => f.key === k)!.test(r));
        });
    }, [rows, search, quick, showZero]);

    const toggleQuick = (k: QuickFilter) => setQuick((cur) => (cur.includes(k) ? cur.filter((x) => x !== k) : [...cur, k]));

    const RefreshBtn = ({ r }: { r: SupplierCcRow }) => (
        <Button size="sm" color="outline-primary" title="Atualizar este fornecedor a partir do PingWin" aria-label={`Atualizar ${r.name ?? "fornecedor"} a partir do PingWin`}
            disabled={!!busy[r.supplier_id]} onClick={(e) => { e.stopPropagation(); refresh(r.supplier_id); }}>
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

    const columns: DTColumn<SupplierCcRow>[] = [
        { id: "code", header: "Código", value: (r) => r.code, nowrap: true, defaultVisible: false, mobile: "subtitle" },
        { id: "name", header: "Fornecedor", value: (r) => r.name, cell: (r) => <span className="fw-medium">{r.name || "—"}</span>, mobile: "title" },
        { id: "nif", header: "NIF", value: (r) => r.tax_number, nowrap: true },
        { id: "pingwin", header: "Saldo PingWin", value: (r) => r.pingwin_balance_cents, cell: (r) => <span className="text-muted">{eur(r.pingwin_balance_cents)}</span>, align: "end", nowrap: true },
        { id: "real", header: "Saldo real", value: (r) => r.real_balance_cents, cell: (r) => <span className="fw-semibold">{eur(r.real_balance_cents)}</span>, align: "end", nowrap: true },
        { id: "diff", header: "Diferença", value: (r) => r.difference_cents, cell: (r) => eur(r.difference_cents), cellClassName: (r) => (r.difference_cents !== 0 ? "text-warning" : "text-muted"), align: "end", nowrap: true },
        { id: "overdue", header: "Vencido", value: (r) => r.overdue_cents, cell: (r) => eur(r.overdue_cents), cellClassName: (r) => (r.overdue_cents > 0 ? "text-danger" : "text-muted"), align: "end", nowrap: true },
        { id: "open_docs", header: "Docs por liquidar", value: (r) => r.open_docs, align: "end" },
        { id: "next_due", header: "Próximo vencimento", value: (r) => r.next_due_date, cell: (r) => fmtDay(r.next_due_date), nowrap: true, defaultVisible: false },
        { id: "status", header: "Estado", value: (r) => r.status, cell: (r) => <CcStatusBadge status={busy[r.supplier_id] ? "syncing" : r.status} error={r.last_error} id={`st-${r.supplier_id}`} />, align: "center" },
        { id: "synced", header: "Última sync", value: (r) => r.synced_at, cell: (r) => <span className="fs-12 text-muted">{fmtInstant(r.synced_at)}</span>, nowrap: true, defaultVisible: false },
    ];
    const cols = useDataColumns("restauracao.conta-corrente", columns);
    const filtersOn = quick.length + (showZero ? 1 : 0);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title="Conta Corrente Fornecedores"
                    breadcrumbs={[{ label: "Restauração" }]}
                    info="O saldo real de cada fornecedor, sem as faturas-recibo de compra já pagas que o PingWin conta como dívida. Positivo = em dívida."
                />

                <Row className="g-3 mb-1">
                    <Col xs={12} sm={6} xl={3}><Kpi id="kpi-real" label="Saldo real total (em dívida)" value={eur(cards?.real_balance_cents)} sub={cards ? <>PingWin: {eur(cards.pingwin_balance_cents)}</> : null} /></Col>
                    <Col xs={12} sm={6} xl={3}><Kpi id="kpi-vencido" label="Vencido" value={eur(cards?.overdue_cents)} tone={(cards?.overdue_cents ?? 0) > 0 ? "text-danger" : undefined} /></Col>
                    <Col xs={12} sm={6} xl={3}><Kpi id="kpi-diff" label="Contado a mais pelo PingWin" value={eur(cards?.difference_cents)} tip={DIFF_TOOLTIP} tone="text-warning" /></Col>
                    <Col xs={12} sm={6} xl={3}><Kpi id="kpi-problem" label="Fornecedores com problema" value={cards?.problem_suppliers ?? "—"} sub="Sync falhada ou não reconciliados" tone={(cards?.problem_suppliers ?? 0) > 0 ? "text-danger" : undefined} /></Col>
                </Row>

                <PageCard
                    title="Fornecedores"
                    loading={loading && rows.length > 0}
                    status={zeroHidden && !showZero ? <>{zeroHidden} sem movimento escondidos</> : undefined}
                    actions={cols.selector}
                    filters={
                        <RestFilterBar
                            search={search}
                            onSearchChange={setSearch}
                            searchPlaceholder="Pesquisar por nome, código ou NIF"
                            activeCount={filtersOn}
                            onClear={() => { setSearch(""); setQuick([]); setShowZero(false); }}
                        >
                            <div className="d-flex flex-wrap align-items-center gap-1" style={{ flex: "1 1 100%" }}>
                                {QUICK.map((f) => {
                                    const on = quick.includes(f.key);
                                    return (
                                        <button key={f.key} type="button" onClick={() => toggleQuick(f.key)} aria-pressed={on}
                                            className={`btn btn-sm ${on ? "btn-outline-primary active" : "btn-light text-muted"}`} style={{ borderRadius: 999 }}>
                                            {on && <i className="ri-check-line me-1" />}{f.label}
                                        </button>
                                    );
                                })}
                                <div className="form-check ms-2 mb-0">
                                    <input className="form-check-input" type="checkbox" id="cc-show-zero" checked={showZero} onChange={(e) => setShowZero(e.target.checked)} />
                                    <label className="form-check-label fs-12" htmlFor="cc-show-zero">Mostrar os sem movimento{zeroHidden ? ` (${zeroHidden})` : ""}</label>
                                </div>
                            </div>
                        </RestFilterBar>
                    }
                >
                    <DataTable
                        columns={cols}
                        data={visible}
                        rowKey={(r) => r.supplier_id}
                        loading={loading}
                        initialSort={{ id: "real", desc: true }}
                        onRowClick={go}
                        rowActions={(r) => <RefreshBtn r={r} />}
                        caption="Conta corrente dos fornecedores"
                        empty={{ message: "Sem fornecedores com estes filtros." }}
                    />
                </PageCard>
            </Container>
        </div>
    );
}
