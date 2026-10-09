import { useCallback, useEffect, useRef, useState } from "react";
import { Button, Card, Col, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { useIsMobile } from "../../hooks/useIsMobile";
import Pagination from "Components/Common/Pagination";
import ReasonButton from "Components/Common/ReasonButton";
import {
    getPingwinSupplierDocuments,
    getPingwinSupplierDocumentsRun,
    syncPingwinSupplierDocuments,
} from "helpers/laravel_helper";
import { LaravelPaginator } from "common/models/pingwin.model";
import {
    SupplierDocumentRow,
    SupplierDocumentsFacets,
    SupplierDocumentsRun,
} from "common/models/supplierDocuments.model";

/**
 * XPLENDOR — Restauração › Faturas › "Documentos PingWin" (F1, SÓ LEITURA).
 * Documentos de fornecedor lançados no PingWin (lista "Documentos" do BO). A madrugada
 * sincroniza os últimos 7 dias; o botão "Sincronizar" pede qualquer período (assíncrono:
 * cria um run e faz polling até terminar). O "Nº doc. fornecedor" em falta fica a âmbar
 * (procedimento de lançamento: o nº da fatura do fornecedor tem de ser preenchido).
 */

const PER_PAGE = 25;
const POLL_MS = 2500;

/** Tipos por omissão (iguais ao backend): FV, FR compra, NC e ND de fornecedor. */
const DEFAULT_TYPES = ["1209", "584955579139752236", "1205", "1206"];
const DEFAULT_TYPE_LABELS: Record<string, string> = {
    "1209": "Fatura de fornecedor",
    "584955579139752236": "Fatura-recibo compra",
    "1205": "Nota de crédito de fornecedor",
    "1206": "Nota de débito de fornecedor",
};

const isoDay = (d: Date) => {
    const p = (n: number) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};
const today = () => isoDay(new Date());
const daysAgo = (n: number) => { const d = new Date(); d.setDate(d.getDate() - n); return isoDay(d); };
const fmtDay = (d?: string | null) => (d ? d.split("-").reverse().join("/") : "—");
const fmtInstant = (d?: string | null) => (d ? new Date(d).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" }) : "—");
const euro = (n: number) => n.toLocaleString("pt-PT", { style: "currency", currency: "EUR" });

const isActive = (r?: SupplierDocumentsRun | null) => !!r && (r.status === "queued" || r.status === "running");

function StatusBadge({ row }: { row: SupplierDocumentRow }) {
    const s = row.docstatus_description || "—";
    const cls = row.docstatus_id === "8003" ? "bg-danger-subtle text-danger"
        : s === "Fechado" ? "bg-success-subtle text-success" : "bg-secondary-subtle text-secondary";
    return <span className={`badge ${cls}`}>{s}</span>;
}

function SupplierRef({ value }: { value: string | null }) {
    return value
        ? <span>{value}</span>
        : <span className="badge bg-warning-subtle text-warning" title="O nº da fatura do fornecedor não foi preenchido no PingWin">Em falta</span>;
}

export default function PingwinSupplierDocumentsTab({ companyId }: { companyId: number | null }) {
    const isMobile = useIsMobile();

    // Filtros
    const [from, setFrom] = useState(daysAgo(7));
    const [to, setTo] = useState(today());
    const [types, setTypes] = useState<string[]>(DEFAULT_TYPES);
    const [supplier, setSupplier] = useState("");
    const [missingRef, setMissingRef] = useState(false);
    const [page, setPage] = useState(1);

    // Dados
    const [rows, setRows] = useState<SupplierDocumentRow[]>([]);
    const [meta, setMeta] = useState<Omit<LaravelPaginator<SupplierDocumentRow>, "data"> | null>(null);
    const [facets, setFacets] = useState<SupplierDocumentsFacets | null>(null);
    const [lastRun, setLastRun] = useState<SupplierDocumentsRun | null>(null);
    const [loading, setLoading] = useState(false);

    // Sync
    const [syncOpen, setSyncOpen] = useState(false);
    const [syncFrom, setSyncFrom] = useState(daysAgo(7));
    const [syncTo, setSyncTo] = useState(today());
    const [syncBusy, setSyncBusy] = useState(false);
    const [activeRun, setActiveRun] = useState<SupplierDocumentsRun | null>(null);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinSupplierDocuments(companyId, {
                page, perPage: PER_PAGE, from: from || undefined, to: to || undefined,
                types: types.length ? types.join(",") : undefined,
                supplier: supplier || undefined, missing_ref: missingRef ? 1 : undefined,
            });
            const paginator = res?.data?.documents;
            setRows(paginator?.data ?? []);
            const { data: _omit, ...m } = paginator ?? {};
            setMeta(paginator ? (m as any) : null);
            setFacets(res?.data?.facets ?? null);
            const last: SupplierDocumentsRun | null = res?.data?.last_run ?? null;
            setLastRun(last);
            if (isActive(last)) setActiveRun((cur) => cur ?? last);
        } catch {
            setRows([]);
        } finally {
            setLoading(false);
        }
    }, [companyId, page, from, to, types, supplier, missingRef]);

    useEffect(() => { fetchRows(); }, [fetchRows]);

    // Mudar um filtro volta à página 1 no MESMO render (um só pedido, sem corrida).
    const withPage1 = <T,>(set: (v: T) => void) => (v: T) => { set(v); setPage(1); };
    const changeFrom = withPage1(setFrom);
    const changeTo = withPage1(setTo);
    const changeTypes = withPage1(setTypes);
    const changeSupplier = withPage1(setSupplier);
    const changeMissingRef = withPage1(setMissingRef);

    // Polling do run ativo até terminar → recarrega a lista.
    useEffect(() => {
        if (!companyId || !activeRun) return;
        const runId = activeRun.id;
        pollRef.current = setInterval(async () => {
            try {
                const res: any = await getPingwinSupplierDocumentsRun(companyId, runId);
                const run: SupplierDocumentsRun | undefined = res?.data?.run;
                if (!run || isActive(run)) return;
                setActiveRun(null);
                setLastRun(run);
                if (run.status === "ok") {
                    toast.success(`Sincronização concluída: ${run.docs_count ?? 0} documento(s).`);
                } else {
                    toast.error(`A sincronização falhou${run.error ? `: ${run.error}` : "."}`);
                }
                fetchRows();
            } catch { /* tenta no próximo intervalo */ }
        }, POLL_MS);
        return () => { if (pollRef.current) clearInterval(pollRef.current); };
    }, [companyId, activeRun, fetchRows]);

    const openSync = () => { setSyncFrom(from || daysAgo(7)); setSyncTo(to || today()); setSyncOpen(true); };

    const runSync = async () => {
        if (!companyId || !syncFrom || !syncTo) return;
        setSyncBusy(true);
        try {
            const res: any = await syncPingwinSupplierDocuments(companyId, { from: syncFrom, to: syncTo });
            const run: SupplierDocumentsRun = res?.data?.run;
            setActiveRun(run);
            setLastRun(run);
            setSyncOpen(false);
            toast.info("A sincronizar documentos do PingWin…");
        } catch (err: any) {
            if (err?.__status === 409 && err?.errors?.run) {
                setActiveRun(err.errors.run);
                setSyncOpen(false);
                toast.warning("Já há uma sincronização em curso. A acompanhar essa.");
            } else {
                toast.error(err?.message ?? "Não foi possível pedir a sincronização.");
            }
        } finally {
            setSyncBusy(false);
        }
    };

    const typeOptions: { id: string; label: string; count?: number }[] = (() => {
        const seen = new Map<string, { id: string; label: string; count?: number }>();
        DEFAULT_TYPES.forEach((id) => seen.set(id, { id, label: DEFAULT_TYPE_LABELS[id] }));
        (facets?.types ?? []).forEach((t) => seen.set(t.id, { id: t.id, label: t.label || t.id, count: t.count }));
        return Array.from(seen.values());
    })();
    const toggleType = (id: string) => changeTypes(types.includes(id) ? types.filter((t) => t !== id) : [...types, id]);

    const running = isActive(activeRun);

    const LastSync = () => {
        const r = activeRun ?? lastRun;
        if (!r) return <span className="text-muted">Ainda não sincronizado.</span>;
        if (isActive(r)) return <span className="text-info"><Spinner size="sm" className="me-1" style={{ width: 10, height: 10 }} />A sincronizar {fmtDay(r.start_date)} → {fmtDay(r.end_date)}…</span>;
        return (
            <span className={r.status === "ok" ? "text-muted" : "text-danger"}>
                Última sincronização: {fmtInstant(r.finished_at ?? r.created_at)} · {fmtDay(r.start_date)} → {fmtDay(r.end_date)}
                {r.status === "ok" ? <> · {r.docs_count ?? 0} documento(s) · <span className="text-success">OK</span></> : <> · falhou{r.error ? `: ${r.error}` : ""}</>}
                {r.trigger === "nightly" && <span className="badge bg-light text-muted ms-1">madrugada</span>}
            </span>
        );
    };

    return (
        <>
            <Card className="mb-3">
                <div className="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h5 className="card-title mb-1">Documentos de fornecedor no PingWin {loading && <Spinner size="sm" className="ms-1" />}</h5>
                        <div className="fs-12"><LastSync /></div>
                    </div>
                    <Button color="primary" onClick={openSync} disabled={running || !companyId}>
                        {running ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar</>}
                    </Button>
                </div>
                <div className="card-body border-bottom">
                    <Row className="g-2 align-items-end">
                        <Col xs={6} md={2}>
                            <label className="form-label fs-12 mb-1">De</label>
                            <input type="date" className="form-control form-control-sm" value={from} max={to || undefined} onChange={(e) => changeFrom(e.target.value)} />
                        </Col>
                        <Col xs={6} md={2}>
                            <label className="form-label fs-12 mb-1">Até</label>
                            <input type="date" className="form-control form-control-sm" value={to} min={from || undefined} onChange={(e) => changeTo(e.target.value)} />
                        </Col>
                        <Col xs={12} md={4}>
                            <label className="form-label fs-12 mb-1">Fornecedor</label>
                            <select className="form-select form-select-sm" value={supplier} onChange={(e) => changeSupplier(e.target.value)}>
                                <option value="">Todos os fornecedores</option>
                                {(facets?.suppliers ?? []).map((s) => <option key={s.id} value={s.id}>{s.name || s.id}</option>)}
                            </select>
                        </Col>
                        <Col xs={12} md={4}>
                            <div className="form-check mb-1">
                                <input className="form-check-input" type="checkbox" id="sd-missing-ref" checked={missingRef} onChange={(e) => changeMissingRef(e.target.checked)} />
                                <label className="form-check-label fs-13" htmlFor="sd-missing-ref">Só sem nº doc. fornecedor</label>
                            </div>
                        </Col>
                    </Row>
                    <div className="d-flex flex-wrap align-items-center gap-1 mt-2">
                        <span className="fs-12 text-muted me-1">Tipo:</span>
                        {typeOptions.map((t) => {
                            const on = types.includes(t.id);
                            return (
                                <button key={t.id} type="button" onClick={() => toggleType(t.id)}
                                    className={`btn btn-sm ${on ? "btn-soft-primary" : "btn-light text-muted"}`} style={{ borderRadius: 999 }}>
                                    {on && <i className="ri-check-line me-1" />}{t.label}{t.count !== undefined && <span className="ms-1 opacity-75">({t.count})</span>}
                                </button>
                            );
                        })}
                        <button type="button" className="btn btn-sm btn-link fs-12" onClick={() => changeTypes(DEFAULT_TYPES)}>Repor</button>
                        <button type="button" className="btn btn-sm btn-link fs-12" onClick={() => changeTypes([])}>Todos os tipos</button>
                    </div>
                </div>

                {isMobile ? (
                    <div className="p-3 d-flex flex-column gap-2">
                        {!loading && rows.length === 0 ? (
                            <div className="text-center text-muted py-4">Sem documentos neste período. Use <strong>“Sincronizar”</strong>.</div>
                        ) : rows.map((r) => (
                            <div key={r.id} style={{ border: "1px solid var(--vz-border-color)", borderRadius: 12, padding: "12px 14px", background: "var(--vz-card-bg)" }}>
                                <div className="d-flex align-items-start justify-content-between gap-2">
                                    <div style={{ minWidth: 0 }}>
                                        <div className="fw-semibold text-body text-truncate">{r.entity_name || "—"}</div>
                                        <div className="text-muted fs-12">{r.document || "—"} · {fmtDay(r.doc_date)} · {r.doctype}</div>
                                    </div>
                                    <StatusBadge row={r} />
                                </div>
                                <div className="d-flex justify-content-between align-items-center mt-2">
                                    <span className="fs-12">Nº doc. forn.: <SupplierRef value={r.docreference_number} /></span>
                                    <span className="fw-semibold">{euro(r.total)}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="table-responsive">
                        <table className="table table-bordered table-hover align-middle mb-0">
                            <thead className="text-muted table-light">
                                <tr>
                                    <th>Data</th>
                                    <th>Documento</th>
                                    <th>Tipo</th>
                                    <th>Fornecedor</th>
                                    <th>NIF</th>
                                    <th>Nº doc. fornecedor</th>
                                    <th className="text-end">Total</th>
                                    <th className="text-center">Liquidado</th>
                                    <th className="text-center">Estado</th>
                                    <th>Lançado por</th>
                                    <th>Loja</th>
                                </tr>
                            </thead>
                            <tbody>
                                {!loading && rows.length === 0 ? (
                                    <tr><td colSpan={11} className="text-center text-muted py-4">Sem documentos neste período. Use <strong>“Sincronizar”</strong>.</td></tr>
                                ) : rows.map((r) => (
                                    <tr key={r.id} className={r.docstatus_id === "8003" ? "text-muted" : undefined}>
                                        <td className="text-nowrap">{fmtDay(r.doc_date)}</td>
                                        <td className="fw-medium text-nowrap">{r.document || "—"}</td>
                                        <td>{r.doctype || "—"}</td>
                                        <td>{r.entity_name || "—"}</td>
                                        <td className="text-nowrap">{r.tax_number || "—"}</td>
                                        <td className={r.docreference_number ? undefined : "bg-warning-subtle"}><SupplierRef value={r.docreference_number} /></td>
                                        <td className="text-end text-nowrap" style={r.docstatus_id === "8003" ? { textDecoration: "line-through" } : undefined}>{euro(r.total)}</td>
                                        <td className="text-center">{r.paid ? <span className="badge bg-success-subtle text-success">Sim</span> : <span className="text-muted">Não</span>}</td>
                                        <td className="text-center"><StatusBadge row={r} /></td>
                                        <td>{r.employee_name || "—"}</td>
                                        <td>{r.store_name || "—"}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            {meta && meta.total > 0 && (
                <Pagination
                    currentPage={meta.current_page} lastPage={meta.last_page} total={meta.total}
                    perPage={meta.per_page} from={meta.from ?? 0} to={meta.to ?? 0}
                    onPageChange={(p) => setPage(p)}
                />
            )}

            <Modal isOpen={syncOpen} toggle={() => !syncBusy && setSyncOpen(false)} centered>
                <ModalHeader toggle={() => !syncBusy && setSyncOpen(false)}>Sincronizar documentos do PingWin</ModalHeader>
                <ModalBody>
                    <p className="text-muted fs-13 mb-3">
                        Lê do PingWin os documentos de fornecedor lançados no período (só leitura: nada é alterado no PingWin).
                        A madrugada já sincroniza os últimos 7 dias.
                    </p>
                    <Row className="g-2">
                        <Col md={6}>
                            <label className="form-label fs-12 mb-1">De</label>
                            <input type="date" className="form-control" value={syncFrom} max={syncTo || today()} onChange={(e) => setSyncFrom(e.target.value)} disabled={syncBusy} />
                        </Col>
                        <Col md={6}>
                            <label className="form-label fs-12 mb-1">Até</label>
                            <input type="date" className="form-control" value={syncTo} min={syncFrom || undefined} max={today()} onChange={(e) => setSyncTo(e.target.value)} disabled={syncBusy} />
                        </Col>
                    </Row>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setSyncOpen(false)} disabled={syncBusy}>Cancelar</Button>
                    <ReasonButton color="primary" onClick={runSync} disabled={syncBusy}
                        reason={!syncFrom || !syncTo ? "Indique o período." : syncTo < syncFrom ? "O fim é anterior ao início." : null}>
                        {syncBusy ? <><Spinner size="sm" className="me-1" /> A pedir…</> : <><i className="ri-refresh-line me-1" /> Sincronizar</>}
                    </ReasonButton>
                </ModalFooter>
            </Modal>
        </>
    );
}
