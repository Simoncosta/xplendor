import { useCallback, useEffect, useState } from "react";
import { Button, Card, CardBody, Col, Container, Nav, NavItem, NavLink, Row, Spinner, UncontrolledTooltip } from "reactstrap";
import { Link, useParams } from "react-router-dom";
import { ToastContainer } from "react-toastify";
import classnames from "classnames";
import PageHeader from "Components/Common/PageHeader";
import XSelect from "Components/Common/Select";
import { getSupplierCc, getSupplierCcStatement } from "helpers/laravel_helper";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { SupplierCcOpenDoc, SupplierCcRow, SupplierCcStatement } from "common/models/supplierCc.model";
import { CcStatusBadge, DIFF_TOOLTIP, eur, fmtDay, fmtInstant, isoDay, useSupplierCcRefresh } from "./contaCorrente.shared";

/**
 * XPLENDOR — Restauração › Conta Corrente Fornecedores › fornecedor (S2).
 * "Por liquidar": documentos NÃO auto-pagos com valor em aberto (as NC a abater, com sinal).
 * "Extrato" (layout pedido pelo Everton): período + loja, saldo anterior, débito/crédito,
 * saldo acumulado; as Faturas-recibo auto-pagas aparecem com débito = crédito ("pago no
 * ato"). "Imprimir" usa só CSS de impressão (window.print), A4, com o cabeçalho próprio.
 * Positivo = em dívida ao fornecedor.
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
                        actions={<Link to="/restauracao/conta-corrente" className="btn btn-light"><i className="ri-arrow-left-line me-1" />Voltar</Link>}
                    />

                    <Card className="mb-3">
                        <CardBody>
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
                                    <Col sm={6} lg={2}>
                                        <div className="text-uppercase text-muted fs-11 fw-semibold">Vencido</div>
                                        <div className={`fs-5 fw-semibold ${s.overdue_cents > 0 ? "text-danger" : "text-muted"}`}>{eur(s.overdue_cents)}</div>
                                    </Col>
                                    <Col sm={6} lg={1} className="text-lg-end">
                                        <Button color="primary" size="sm" onClick={() => refresh(supplierId)} disabled={!!busy[supplierId]} title="Atualizar a partir do PingWin">
                                            {busy[supplierId] ? <Spinner size="sm" /> : <i className="ri-refresh-line" />}
                                        </Button>
                                        <div className="text-muted fs-11 mt-1">{fmtInstant(s.synced_at)}</div>
                                    </Col>
                                </Row>
                            )}
                        </CardBody>
                    </Card>

                    <Nav tabs className="nav-tabs-custom mb-3">
                        <NavItem><NavLink className={classnames({ active: tab === "aberto" })} onClick={() => setTab("aberto")} style={{ cursor: "pointer" }}>Por liquidar {detail && <span className="badge bg-light text-muted ms-1">{detail.open.length}</span>}</NavLink></NavItem>
                        <NavItem><NavLink className={classnames({ active: tab === "extrato" })} onClick={() => setTab("extrato")} style={{ cursor: "pointer" }}>Extrato</NavLink></NavItem>
                    </Nav>
                </div>

                {tab === "aberto" && (
                    <Card className="mb-3">
                        <div className="table-responsive">
                            <table className="table table-bordered table-hover align-middle mb-0">
                                <thead className="text-muted table-light">
                                    <tr>
                                        <th>Data</th>
                                        <th>Documento</th>
                                        <th>Doc. Fornecedor</th>
                                        <th className="text-nowrap">Data doc. fornecedor</th>
                                        <th>Vencimento</th>
                                        <th className="text-end text-nowrap">Dias de atraso</th>
                                        <th className="text-end">Total</th>
                                        <th className="text-end text-nowrap">Por liquidar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {loading && !detail ? (
                                        <tr><td colSpan={8} className="text-center py-4"><Spinner size="sm" /></td></tr>
                                    ) : !detail || detail.open.length === 0 ? (
                                        <tr><td colSpan={8} className="text-center text-muted py-4">Nada por liquidar.</td></tr>
                                    ) : detail.open.map((d) => (
                                        <tr key={d.docheader_id}>
                                            <td className="text-nowrap">{fmtDay(d.doc_date)}</td>
                                            <td className="fw-medium text-nowrap">{d.document || "—"}<div className="text-muted fs-11 fw-normal">{d.doctype}</div></td>
                                            <td className={d.docreference_number ? undefined : "bg-warning-subtle"}><SupplierRef value={d.docreference_number} /></td>
                                            <td className="text-nowrap">{fmtDay(d.docreference_date)}</td>
                                            <td className="text-nowrap">
                                                {fmtDay(d.due_date)}
                                                {d.due_is_doc_date && (
                                                    <>
                                                        <i id={`due-${d.docheader_id}`} className="ri-error-warning-line text-warning ms-1" style={{ cursor: "help" }} />
                                                        <UncontrolledTooltip target={`due-${d.docheader_id}`}>Condição de pagamento não aplicada</UncontrolledTooltip>
                                                    </>
                                                )}
                                            </td>
                                            <td className={`text-end ${d.days_overdue > 0 ? "text-danger fw-semibold" : "text-muted"}`}>{d.days_overdue > 0 ? d.days_overdue : "—"}</td>
                                            <td className="text-end text-nowrap">{eur(d.total_cents)}</td>
                                            <td className={`text-end text-nowrap fw-semibold ${d.open_cents < 0 ? "text-success" : ""}`}>{eur(d.open_cents)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                                {detail && detail.open.length > 0 && (
                                    <tfoot className="table-light">
                                        <tr>
                                            <td colSpan={7} className="text-end fw-semibold">Total por liquidar</td>
                                            <td className="text-end text-nowrap fw-bold">{eur(detail.open_total_cents)}</td>
                                        </tr>
                                    </tfoot>
                                )}
                            </table>
                        </div>
                    </Card>
                )}

                {tab === "extrato" && (
                    <div className="cc-print-area">
                        <Card className="mb-3 cc-no-print">
                            <CardBody>
                                <Row className="g-2 align-items-end">
                                    <Col xs={6} md={2}>
                                        <label className="form-label fs-12 mb-1">De</label>
                                        <input type="date" className="form-control form-control-sm" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} />
                                    </Col>
                                    <Col xs={6} md={2}>
                                        <label className="form-label fs-12 mb-1">Até</label>
                                        <input type="date" className="form-control form-control-sm" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} />
                                    </Col>
                                    <Col xs={12} md={4}>
                                        <label className="form-label fs-12 mb-1" htmlFor="cc-store">Loja</label>
                                        <XSelect id="cc-store" ariaLabel="Loja" small value={store} onChange={(v) => setStore(v)} placeholder="Todas as lojas"
                                            options={[{ value: "", label: "Todas as lojas" }, ...(detail?.stores ?? []).map((st) => ({ value: st.id, label: st.name || st.id }))]} />
                                    </Col>
                                    <Col xs={12} md={4} className="text-md-end">
                                        <Button color="light" onClick={() => window.print()} disabled={!stmt}>
                                            <i className="ri-printer-line me-1" />Imprimir
                                        </Button>
                                    </Col>
                                </Row>
                            </CardBody>
                        </Card>

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

                        <Card className="mb-3">
                            <div className="table-responsive">
                                <table className="table table-bordered align-middle mb-0">
                                    <thead className="text-muted table-light">
                                        <tr>
                                            <th>Loja</th>
                                            <th>Data</th>
                                            <th>Documento</th>
                                            <th>Doc. Fornecedor</th>
                                            <th className="text-end">Débito</th>
                                            <th className="text-end">Crédito</th>
                                            <th className="text-end">Saldo</th>
                                            <th className="text-nowrap">Data vencimento</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {stmtLoading && !stmt ? (
                                            <tr><td colSpan={8} className="text-center py-4"><Spinner size="sm" /></td></tr>
                                        ) : !stmt ? (
                                            <tr><td colSpan={8} className="text-center text-muted py-4">Sem extrato.</td></tr>
                                        ) : (
                                            <>
                                                <tr className="table-light">
                                                    <td colSpan={6} className="fw-semibold">Saldo anterior</td>
                                                    <td className="text-end text-nowrap fw-semibold">{eur(stmt.opening_cents)}</td>
                                                    <td />
                                                </tr>
                                                {stmt.lines.length === 0 ? (
                                                    <tr><td colSpan={8} className="text-center text-muted py-3">Sem movimentos no período.</td></tr>
                                                ) : stmt.lines.map((l) => (
                                                    <tr key={l.docheader_id}>
                                                        <td className="text-nowrap">{l.store || "—"}</td>
                                                        <td className="text-nowrap">{fmtDay(l.date)}</td>
                                                        <td className="text-nowrap">
                                                            {l.document || "—"}
                                                            {l.paid_on_issue && <span className="badge bg-info-subtle text-info ms-1">pago no ato</span>}
                                                        </td>
                                                        <td>{l.supplier_doc || ""}</td>
                                                        <td className="text-end text-nowrap">{l.debit_cents ? eur(l.debit_cents) : ""}</td>
                                                        <td className="text-end text-nowrap">{l.credit_cents ? eur(l.credit_cents) : ""}</td>
                                                        <td className="text-end text-nowrap fw-medium">{eur(l.balance_cents)}</td>
                                                        <td className="text-nowrap">{fmtDay(l.due_date)}</td>
                                                    </tr>
                                                ))}
                                            </>
                                        )}
                                    </tbody>
                                    {stmt && (
                                        <tfoot className="table-light">
                                            <tr>
                                                <td colSpan={4} className="fw-semibold">Saldo deste fornecedor</td>
                                                <td className="text-end text-nowrap fw-semibold">{eur(stmt.total_debit_cents)}</td>
                                                <td className="text-end text-nowrap fw-semibold">{eur(stmt.total_credit_cents)}</td>
                                                <td className="text-end text-nowrap fw-bold">{eur(stmt.closing_cents)}</td>
                                                <td />
                                            </tr>
                                        </tfoot>
                                    )}
                                </table>
                            </div>
                        </Card>
                    </div>
                )}
            </Container>
        </div>
    );
}
