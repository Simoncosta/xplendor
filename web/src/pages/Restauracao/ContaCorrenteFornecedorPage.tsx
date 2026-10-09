import { useCallback, useEffect, useState } from "react";
import { Button, Card, CardBody, Col, Container, Nav, NavItem, NavLink, Row, Spinner, UncontrolledTooltip } from "reactstrap";
import { Link, useParams } from "react-router-dom";
import { ToastContainer } from "react-toastify";
import classnames from "classnames";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import XSelect from "Components/Common/Select";
import { getSupplierCc, getSupplierCcStatement } from "helpers/laravel_helper";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { SupplierCcOpenDoc, SupplierCcRow, SupplierCcStatement, SupplierCcStatementLine } from "common/models/supplierCc.model";
import { CcStatusBadge, DIFF_TOOLTIP, eur, fmtDay, fmtInstant, isoDay, useSupplierCcRefresh } from "./contaCorrente.shared";

/**
 * XPLENDOR — Restauração › Conta Corrente Fornecedores › fornecedor (S2).
 * "Por liquidar": documentos NÃO auto-pagos com valor em aberto (as NC a abater, com sinal).
 * "Extrato" (layout pedido pelo Everton): período + loja, saldo anterior, débito/crédito,
 * saldo acumulado; as Faturas-recibo auto-pagas aparecem com débito = crédito ("pago no
 * ato"). "Imprimir" usa só CSS de impressão (window.print), A4, com o cabeçalho próprio.
 * Positivo = em dívida ao fornecedor.
 *
 * UI-2a: um PageCard por separador, com DataTable. O extrato não pagina nem ordena (o saldo
 * acumulado depende da ordem) e mantém o "Saldo anterior", os totais e a impressão A4.
 */

const PRINT_CSS = `
.cc-print-only { display: none; }
@media print {
    @page { size: A4; margin: 12mm; }
    #page-topbar, .cc-no-print, .Toastify, .xplndor-cookie-banner, a[aria-label="Suporte"] { display: none !important; }
    html, body, #layout-wrapper, .main-content, .page-content { background: #fff !important; }
    .main-content { margin-left: 0 !important; }
    .page-content { padding: 0 !important; }
    .cc-print-only { display: block !important; }
    .cc-print-area .card { border: 0 !important; box-shadow: none !important; }
    .cc-print-area .table-responsive { overflow: visible !important; }
    .cc-print-area table { font-size: 10px; }
    .cc-print-area thead { display: table-header-group; }
    .cc-print-area tfoot { display: table-row-group; }
    .cc-print-area tr { break-inside: avoid; }
    .cc-print-area .badge { border: 1px solid #999; }
    .cc-print-area .xp-card-header, .cc-print-area .xp-card-filters { display: none !important; }
}
`;

type Detail = { supplier: SupplierCcRow; open: SupplierCcOpenDoc[]; open_total_cents: number; stores: { id: string; name: string | null }[] };

const SupplierRef = ({ value }: { value: string | null }) =>
    value ? <span>{value}</span> : <span className="badge bg-warning-subtle text-warning" title="O nº da fatura do fornecedor não foi preenchido no PingWin">Em falta</span>;

export default function ContaCorrenteFornecedorPage() {
    const { supplierId: sid } = useParams();
    const supplierId = Number(sid);
    const companyId = useWorkingCompanyId();

    const [tab, setTab] = useState<"aberto" | "extrato">("aberto");
    const [detail, setDetail] = useState<Detail | null>(null);
    const [loading, setLoading] = useState(false);
    const [notFound, setNotFound] = useState(false);

    // Extrato: por omissão de 1 de janeiro do ano corrente até hoje, todas as lojas.
    const [from, setFrom] = useState(`${new Date().getFullYear()}-01-01`);
    const [to, setTo] = useState(isoDay(new Date()));
    const [store, setStore] = useState("");
    const [stmt, setStmt] = useState<SupplierCcStatement | null>(null);
    const [stmtLoading, setStmtLoading] = useState(false);

    document.title = `${detail?.supplier.name ?? "Fornecedor"} | Conta Corrente | Xplendor`;

    const loadDetail = useCallback(async () => {
        if (!companyId || !supplierId) return;
        setLoading(true);
        try {
            const res: any = await getSupplierCc(companyId, supplierId);
            setDetail(res?.data ?? null);
            setNotFound(false);
        } catch (err: any) {
            if (err?.__status === 404) setNotFound(true);
        } finally {
            setLoading(false);
        }
    }, [companyId, supplierId]);

    const loadStatement = useCallback(async () => {
        if (!companyId || !supplierId) return;
        setStmtLoading(true);
        try {
            const res: any = await getSupplierCcStatement(companyId, supplierId, { from: from || undefined, to: to || undefined, store: store || undefined });
            setStmt(res?.data?.statement ?? null);
        } catch {
            setStmt(null);
        } finally {
            setStmtLoading(false);
        }
    }, [companyId, supplierId, from, to, store]);

    useEffect(() => { loadDetail(); }, [loadDetail]);
    useEffect(() => { if (tab === "extrato") loadStatement(); }, [tab, loadStatement]);

    const { busy, refresh } = useSupplierCcRefresh(companyId, () => { loadDetail(); if (tab === "extrato") loadStatement(); });

    const s = detail?.supplier;
    const storeName = store ? (detail?.stores.find((x) => x.id === store)?.name ?? store) : "Todas";

    // "Por liquidar": documentos com valor em aberto (ordenáveis), com o total no fim.
    const openColumns: DTColumn<SupplierCcOpenDoc>[] = [
        { id: "date", header: "Data", value: (d) => d.doc_date, cell: (d) => fmtDay(d.doc_date), nowrap: true, mobile: "subtitle" },
        {
            id: "document", header: "Documento", value: (d) => d.document, mobile: "title",
            cell: (d) => <span className="fw-medium text-nowrap">{d.document || "—"}<div className="text-muted fs-11 fw-normal">{d.doctype}</div></span>,
        },
        { id: "supplier_ref", header: "Doc. Fornecedor", value: (d) => d.docreference_number, cell: (d) => <SupplierRef value={d.docreference_number} />, cellClassName: (d) => (d.docreference_number ? undefined : "bg-warning-subtle") },
        { id: "supplier_ref_date", header: "Data doc. fornecedor", value: (d) => d.docreference_date, cell: (d) => fmtDay(d.docreference_date), nowrap: true },
        {
            id: "due", header: "Vencimento", value: (d) => d.due_date, nowrap: true,
            cell: (d) => <>
                {fmtDay(d.due_date)}
                {d.due_is_doc_date && (
                    <>
                        <i id={`due-${d.docheader_id}`} className="ri-error-warning-line text-warning ms-1" style={{ cursor: "help" }} />
                        <UncontrolledTooltip target={`due-${d.docheader_id}`}>Condição de pagamento não aplicada</UncontrolledTooltip>
                    </>
                )}
            </>,
        },
        { id: "overdue", header: "Dias de atraso", value: (d) => (d.days_overdue > 0 ? d.days_overdue : null), cellClassName: (d) => (d.days_overdue > 0 ? "text-danger fw-semibold" : "text-muted"), align: "end", nowrap: true },
        { id: "total", header: "Total", value: (d) => d.total_cents, cell: (d) => eur(d.total_cents), align: "end", nowrap: true },
        { id: "open", header: "Por liquidar", value: (d) => d.open_cents, cell: (d) => eur(d.open_cents), cellClassName: (d) => `fw-semibold ${d.open_cents < 0 ? "text-success" : ""}`, align: "end", nowrap: true },
    ];
    const openCols = useDataColumns("restauracao.conta-corrente.por-liquidar", openColumns);

    // "Extrato": pela ordem do documento (o saldo acumulado depende dela): sem ordenar.
    const stmtColumns: DTColumn<SupplierCcStatementLine>[] = [
        { id: "store", header: "Loja", value: (l) => l.store, nowrap: true, sortable: false },
        { id: "date", header: "Data", value: (l) => l.date, cell: (l) => fmtDay(l.date), nowrap: true, sortable: false, mobile: "subtitle" },
        {
            id: "document", header: "Documento", value: (l) => l.document, sortable: false, mobile: "title",
            cell: (l) => <span className="text-nowrap">{l.document || "—"}{l.paid_on_issue && <span className="badge bg-info-subtle text-info ms-1">pago no ato</span>}</span>,
        },
        { id: "supplier_doc", header: "Doc. Fornecedor", value: (l) => l.supplier_doc, cell: (l) => l.supplier_doc || "", sortable: false },
        { id: "debit", header: "Débito", value: (l) => l.debit_cents, cell: (l) => (l.debit_cents ? eur(l.debit_cents) : ""), align: "end", nowrap: true, sortable: false },
        { id: "credit", header: "Crédito", value: (l) => l.credit_cents, cell: (l) => (l.credit_cents ? eur(l.credit_cents) : ""), align: "end", nowrap: true, sortable: false },
        { id: "balance", header: "Saldo", value: (l) => l.balance_cents, cell: (l) => <span className="fw-medium">{eur(l.balance_cents)}</span>, align: "end", nowrap: true, sortable: false },
        { id: "due", header: "Data vencimento", value: (l) => l.due_date, cell: (l) => fmtDay(l.due_date), nowrap: true, sortable: false },
    ];
    const stmtCols = useDataColumns("restauracao.conta-corrente.extrato", stmtColumns);

    if (notFound) {
        return (
            <div className="page-content"><Container fluid>
                <PageHeader title="Fornecedor" breadcrumbs={[{ label: "Restauração" }, { label: "Conta Corrente Fornecedores", to: "/restauracao/conta-corrente" }]} />
                <Card><CardBody className="text-center text-muted py-5">Fornecedor não encontrado. <Link to="/restauracao/conta-corrente">Voltar à lista</Link></CardBody></Card>
            </Container></div>
        );
    }

    return (
        <div className="page-content">
            <style>{PRINT_CSS}</style>
            <ToastContainer />
            <Container fluid>
                <div className="cc-no-print">
                    <PageHeader
                        title={s?.name ?? "Fornecedor"}
                        breadcrumbs={[{ label: "Restauração" }, { label: "Conta Corrente Fornecedores", to: "/restauracao/conta-corrente" }]}
                        info="A conta corrente do fornecedor: o que está por liquidar e o extrato. Positivo = em dívida."
                    />

                    <PageCard
                        title="Resumo"
                        flush={false}
                        status={s ? <>Atualizado a {fmtInstant(s.synced_at)}</> : undefined}
                        actions={<>
                            <Link to="/restauracao/conta-corrente" className="btn btn-light"><i className="ri-arrow-left-line me-1" />Voltar</Link>
                            <Button color="outline-primary" onClick={() => refresh(supplierId)} disabled={!!busy[supplierId] || !s} title="Atualizar a partir do PingWin">
                                {busy[supplierId] ? <><Spinner size="sm" className="me-1" />A atualizar…</> : <><i className="ri-refresh-line me-1" />Atualizar</>}
                            </Button>
                        </>}
                    >
                        {!s ? (
                            <div className="text-center py-3"><Spinner size="sm" /></div>
                        ) : (
                            <Row className="g-3 align-items-center">
                                <Col lg={3}>
                                    <div className="fw-semibold fs-16">{s.name}</div>
                                    <div className="text-muted fs-12">Código {s.code || "—"} · NIF {s.tax_number || "—"}</div>
                                    <div className="mt-2"><CcStatusBadge status={busy[supplierId] ? "syncing" : s.status} error={s.last_error} id="det-status" /></div>
                                </Col>
                                <Col sm={6} lg={3}>
                                    <div className="text-uppercase text-muted fs-11 fw-semibold">Saldo real</div>
                                    <div className="fs-3 fw-semibold">{eur(s.real_balance_cents)}</div>
                                </Col>
                                <Col sm={6} lg={3}>
                                    <div className="text-uppercase text-muted fs-11 fw-semibold">Saldo PingWin</div>
                                    <div className="fs-5 text-muted">{eur(s.pingwin_balance_cents)}</div>
                                    {s.difference_cents !== 0 && (
                                        <div className="fs-12 text-warning" id="det-diff" style={{ cursor: "help" }}>
                                            +{eur(s.difference_cents)} contados a mais <i className="ri-information-line" />
                                            <UncontrolledTooltip target="det-diff">{DIFF_TOOLTIP}</UncontrolledTooltip>
                                        </div>
                                    )}
                                </Col>
                                <Col sm={6} lg={3}>
                                    <div className="text-uppercase text-muted fs-11 fw-semibold">Vencido</div>
                                    <div className={`fs-5 fw-semibold ${s.overdue_cents > 0 ? "text-danger" : "text-muted"}`}>{eur(s.overdue_cents)}</div>
                                </Col>
                            </Row>
                        )}
                    </PageCard>

                    <Nav tabs className="nav-tabs-custom mb-3">
                        <NavItem><NavLink className={classnames({ active: tab === "aberto" })} onClick={() => setTab("aberto")} style={{ cursor: "pointer" }}>Por liquidar {detail && <span className="badge bg-light text-muted ms-1">{detail.open.length}</span>}</NavLink></NavItem>
                        <NavItem><NavLink className={classnames({ active: tab === "extrato" })} onClick={() => setTab("extrato")} style={{ cursor: "pointer" }}>Extrato</NavLink></NavItem>
                    </Nav>
                </div>

                {tab === "aberto" && (
                    <PageCard
                        title="Por liquidar"
                        loading={loading && !!detail}
                        status={detail ? <>{detail.open.length} {detail.open.length === 1 ? "documento" : "documentos"} · total {eur(detail.open_total_cents)}</> : undefined}
                        actions={openCols.selector}
                    >
                        <DataTable
                            columns={openCols}
                            data={detail?.open ?? []}
                            rowKey={(d) => d.docheader_id}
                            loading={loading && !detail}
                            initialSort={{ id: "date" }}
                            caption="Documentos por liquidar"
                            empty={{ message: "Nada por liquidar." }}
                            footerRow={detail && detail.open.length > 0 ? { label: "Total por liquidar", cells: { open: <span className="fw-bold">{eur(detail.open_total_cents)}</span> } } : undefined}
                        />
                    </PageCard>
                )}

                {tab === "extrato" && (
                    <div className="cc-print-area">
                        {/* Cabeçalho só de impressão (pedido pelo Everton). */}
                        <div className="cc-print-only mb-3">
                            <h4 className="mb-2">Conta Corrente Fornecedor</h4>
                            <table className="table table-sm table-borderless mb-0" style={{ width: "auto" }}>
                                <tbody>
                                    <tr><td className="fw-semibold pe-3">Fornecedor</td><td>{s?.name}{s?.code ? ` (${s.code})` : ""}{s?.tax_number ? ` · NIF ${s.tax_number}` : ""}</td></tr>
                                    <tr><td className="fw-semibold pe-3">Loja</td><td>{storeName}</td></tr>
                                    <tr><td className="fw-semibold pe-3">Data de início</td><td>{fmtDay(from)}</td></tr>
                                    <tr><td className="fw-semibold pe-3">Data de fim</td><td>{fmtDay(to)}</td></tr>
                                    <tr><td className="fw-semibold pe-3">Impresso em</td><td>{new Date().toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" })}</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <PageCard
                            title="Extrato"
                            loading={stmtLoading && !!stmt}
                            status={<>{fmtDay(from)} a {fmtDay(to)} · {storeName === "Todas" ? "todas as lojas" : storeName}</>}
                            actions={<>
                                {stmtCols.selector}
                                <Button color="outline-primary" onClick={() => window.print()} disabled={!stmt}>
                                    <i className="ri-printer-line me-1" />Imprimir
                                </Button>
                            </>}
                            filters={
                                <Row className="g-2 align-items-end">
                                    <Col xs={6} md={3}>
                                        <label className="form-label fs-12 mb-1" htmlFor="cc-from">De</label>
                                        <input id="cc-from" type="date" className="form-control form-control-sm" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} />
                                    </Col>
                                    <Col xs={6} md={3}>
                                        <label className="form-label fs-12 mb-1" htmlFor="cc-to">Até</label>
                                        <input id="cc-to" type="date" className="form-control form-control-sm" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} />
                                    </Col>
                                    <Col xs={12} md={6}>
                                        <label className="form-label fs-12 mb-1" htmlFor="cc-store">Loja</label>
                                        <XSelect id="cc-store" ariaLabel="Loja" small value={store} onChange={(v) => setStore(v)} placeholder="Todas as lojas"
                                            options={[{ value: "", label: "Todas as lojas" }, ...(detail?.stores ?? []).map((st) => ({ value: st.id, label: st.name || st.id }))]} />
                                    </Col>
                                </Row>
                            }
                        >
                            <DataTable
                                columns={stmtCols}
                                data={stmt?.lines ?? []}
                                rowKey={(l) => l.docheader_id}
                                loading={stmtLoading && !stmt}
                                paginate={false}
                                caption="Extrato do fornecedor"
                                empty={{ message: stmt ? "Sem movimentos no período." : "Sem extrato." }}
                                leadingRow={stmt ? { label: "Saldo anterior", cells: { balance: eur(stmt.opening_cents) } } : undefined}
                                footerRow={stmt ? {
                                    label: "Saldo deste fornecedor",
                                    cells: { debit: eur(stmt.total_debit_cents), credit: eur(stmt.total_credit_cents), balance: <span className="fw-bold">{eur(stmt.closing_cents)}</span> },
                                } : undefined}
                            />
                        </PageCard>
                    </div>
                )}
            </Container>
        </div>
    );
}
