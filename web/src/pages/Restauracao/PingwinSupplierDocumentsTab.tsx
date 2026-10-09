import { useCallback, useEffect, useRef, useState } from "react";
import { Button, Col, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { Link } from "react-router-dom";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import XSelect from "Components/Common/Select";
import RestFilterBar from "Components/Common/RestFilterBar";
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
 *
 * UI-1: PageCard (ações e estado no cabeçalho) + DataTable. Os filtros (período, tipos,
 * fornecedor) vão à API; a tabela ordena, pesquisa e pagina no browser. Com mais de 500
 * documentos no período, a tabela pagina no servidor e a ordenação fica desligada.
 *
 * F3: coluna "OCR" (escondida por omissão): ✓ quando há uma fatura carregada ligada.
 */

const API_PER_PAGE = 100;
const CLIENT_LIMIT = 500;
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
const isVoided = (r: SupplierDocumentRow) => r.docstatus_id === "8003";

function StatusBadge({ row }: { row: SupplierDocumentRow }) {
    const s = row.docstatus_description || "—";
    const cls = isVoided(row) ? "bg-danger-subtle text-danger"
        : s === "Fechado" ? "bg-success-subtle text-success" : "bg-secondary-subtle text-secondary";
    return <span className={`badge ${cls}`}>{s}</span>;
}

function SupplierRef({ value }: { value: string | null }) {
    return value
        ? <span>{value}</span>
        : <span className="badge bg-warning-subtle text-warning" title="O nº da fatura do fornecedor não foi preenchido no PingWin">Em falta</span>;
}

/** As colunas (regra 5: Data, Documento, Tipo, Fornecedor, NIF, Total, Liquidado, Estado, Loja). */
const COLUMNS: DTColumn<SupplierDocumentRow>[] = [
    { id: "date", header: "Data", value: (r) => `${r.doc_date ?? ""} ${r.doc_time ?? ""}`.trim() || null, cell: (r) => fmtDay(r.doc_date), nowrap: true, mobile: "subtitle" },
    { id: "document", header: "Documento", value: (r) => r.document, cell: (r) => <span className="fw-medium">{r.document || "—"}</span>, nowrap: true, mobile: "subtitle" },
    { id: "type", header: "Tipo", value: (r) => r.doctype },
    { id: "supplier", header: "Fornecedor", value: (r) => r.entity_name, mobile: "title" },
    { id: "nif", header: "NIF", value: (r) => r.tax_number, nowrap: true },
    {
        id: "total", header: "Total", value: (r) => r.total, align: "end", nowrap: true,
        cell: (r) => <span style={isVoided(r) ? { textDecoration: "line-through" } : undefined}>{euro(r.total)}</span>,
    },
    {
        id: "paid", header: "Liquidado", value: (r) => (r.paid ? 1 : 0), align: "center",
        cell: (r) => (r.paid ? <span className="badge bg-success-subtle text-success">Sim</span> : <span className="text-muted">Não</span>),
    },
    { id: "status", header: "Estado", value: (r) => r.docstatus_description, align: "center", cell: (r) => <StatusBadge row={r} /> },
    { id: "store", header: "Loja", value: (r) => r.store_name },
    {
        id: "supplier_ref", header: "Nº doc. fornecedor", value: (r) => r.docreference_number, defaultVisible: false,
        cell: (r) => <SupplierRef value={r.docreference_number} />,
        cellClassName: (r) => (r.docreference_number ? undefined : "bg-warning-subtle"),
    },
    { id: "employee", header: "Lançado por", value: (r) => r.employee_name, defaultVisible: false },
    {
        // F3: há uma fatura carregada (OCR) ligada a este documento.
        id: "ocr", header: "OCR", value: (r) => (r.ocr_invoice_id ? 1 : 0), align: "center", defaultVisible: false,
        cell: (r) => (r.ocr_invoice_id
            ? <Link to={`/restauracao/faturas/${r.ocr_invoice_id}`} className="text-success" title="Fatura carregada ligada a este documento" aria-label="Abrir a fatura carregada" onClick={(e) => e.stopPropagation()}><i className="ri-check-line fs-16" /></Link>
            : <span className="text-muted">—</span>),
    },
];

type Meta = Omit<LaravelPaginator<SupplierDocumentRow>, "data">;

export default function PingwinSupplierDocumentsTab({ companyId }: { companyId: number | null }) {
    // Filtros (vão à API)
    const [from, setFrom] = useState(daysAgo(7));
    const [to, setTo] = useState(today());
    const [types, setTypes] = useState<string[]>(DEFAULT_TYPES);
    const [supplier, setSupplier] = useState("");
    const [missingRef, setMissingRef] = useState(false);
    // Pesquisa (no browser)
    const [search, setSearch] = useState("");
    // Modo servidor (muitos documentos): página pedida à API
    const [serverMode, setServerMode] = useState(false);
    const serverRef = useRef(false); // o mesmo, para o pedido (mudar o modo não pede outra vez)
    const [page, setPage] = useState(1);

    // Dados
    const [rows, setRows] = useState<SupplierDocumentRow[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
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

    const cols = useDataColumns("restauracao.faturas.documentos-pingwin", COLUMNS);

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        const params = {
            from: from || undefined, to: to || undefined,
            types: types.length ? types.join(",") : undefined,
            supplier: supplier || undefined, missing_ref: missingRef ? 1 : undefined,
        };
        try {
            const first: any = await getPingwinSupplierDocuments(companyId, { ...params, page: serverRef.current ? page : 1, perPage: API_PER_PAGE });
            const paginator = first?.data?.documents;
            setFacets(first?.data?.facets ?? null);
            const last: SupplierDocumentsRun | null = first?.data?.last_run ?? null;
            setLastRun(last);
            if (isActive(last)) setActiveRun((cur) => cur ?? last);

            const total: number = paginator?.total ?? 0;
            if (total > CLIENT_LIMIT) {
                // Muitos documentos: uma página de cada vez, pedida ao servidor.
                serverRef.current = true;
                setServerMode(true);
                setRows(paginator?.data ?? []);
                const { data: _omit, ...m } = paginator ?? {};
                setMeta(paginator ? (m as Meta) : null);
                return;
            }
            // Poucos: lê todas as páginas e deixa a tabela ordenar, pesquisar e paginar.
            let all: SupplierDocumentRow[] = paginator?.data ?? [];
            for (let p = 2; p <= (paginator?.last_page ?? 1); p++) {
                const next: any = await getPingwinSupplierDocuments(companyId, { ...params, page: p, perPage: API_PER_PAGE });
                all = all.concat(next?.data?.documents?.data ?? []);
            }
            serverRef.current = false;
            setServerMode(false);
            setRows(all);
            setMeta(null);
        } catch {
            setRows([]);
            setMeta(null);
        } finally {
            setLoading(false);
        }
    }, [companyId, from, to, types, supplier, missingRef, page]);

    useEffect(() => { fetchRows(); }, [fetchRows]);

    // Mudar um filtro volta à página 1 e reavalia o modo (um só pedido, sem corrida).
    const withReset = <T,>(set: (v: T) => void) => (v: T) => { set(v); setPage(1); serverRef.current = false; };
    const changeFrom = withReset(setFrom);
    const changeTo = withReset(setTo);
    const changeTypes = withReset(setTypes);
    const changeSupplier = withReset(setSupplier);
    const changeMissingRef = withReset(setMissingRef);

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

    const supplierOptions = [
        { value: "", label: "Todos os fornecedores" },
        ...(facets?.suppliers ?? []).map((s) => ({ value: s.id, label: s.name || s.id })),
    ];

    const running = isActive(activeRun);
    const typesChanged = [...types].sort().join() !== [...DEFAULT_TYPES].sort().join();
    const filtersChanged = [supplier !== "", missingRef, from !== daysAgo(7), to !== today(), typesChanged].filter(Boolean).length;
    const clearFilters = () => { setSearch(""); changeFrom(daysAgo(7)); changeTo(today()); changeSupplier(""); changeMissingRef(false); changeTypes(DEFAULT_TYPES); };

    const lastSync = (() => {
        const r = activeRun ?? lastRun;
        if (!r) return <span>Ainda não sincronizado.</span>;
        if (isActive(r)) return <span className="text-info"><Spinner size="sm" className="me-1" style={{ width: 10, height: 10 }} />A sincronizar {fmtDay(r.start_date)} → {fmtDay(r.end_date)}…</span>;
        return (
            <span className={r.status === "ok" ? undefined : "text-danger"}>
                Última sincronização: {fmtInstant(r.finished_at ?? r.created_at)} · {fmtDay(r.start_date)} → {fmtDay(r.end_date)}
                {r.status === "ok" ? <> · {r.docs_count ?? 0} documento(s)</> : <> · falhou{r.error ? `: ${r.error}` : ""}</>}
                {r.trigger === "nightly" && <span className="badge bg-light text-muted ms-1">madrugada</span>}
            </span>
        );
    })();

    const fieldLabel = (text: string) => (
        <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>{text}</Label>
    );

    return (
        <>
            <PageCard
                title="Documentos de fornecedor no PingWin"
                info="Os documentos de fornecedor lançados no PingWin, só para consulta. A madrugada sincroniza os últimos 7 dias."
                status={<>
                    {lastSync}
                    {serverMode && <div className="text-warning">Mais de {CLIENT_LIMIT} documentos no período: a ordenação e a pesquisa ficam desligadas. Reduza o período para as usar.</div>}
                </>}
                loading={loading && rows.length > 0}
                actions={<>
                    {cols.selector}
                    <Button color="primary" onClick={openSync} disabled={running || !companyId}>
                        {running ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar</>}
                    </Button>
                </>}
                filters={<>
                    <RestFilterBar
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Pesquisar (documento, fornecedor, NIF)…"
                        activeCount={filtersChanged}
                        onClear={clearFilters}
                    >
                        <div style={{ flex: "0 1 150px" }}>
                            {fieldLabel("De")}
                            <input type="date" className="form-control form-control-sm" value={from} max={to || undefined} onChange={(e) => changeFrom(e.target.value)} aria-label="De" />
                        </div>
                        <div style={{ flex: "0 1 150px" }}>
                            {fieldLabel("Até")}
                            <input type="date" className="form-control form-control-sm" value={to} min={from || undefined} onChange={(e) => changeTo(e.target.value)} aria-label="Até" />
                        </div>
                        <div style={{ flex: "1 1 220px", minWidth: 0 }}>
                            {fieldLabel("Fornecedor")}
                            <XSelect id="sd-supplier" ariaLabel="Fornecedor" small options={supplierOptions} value={supplier}
                                onChange={(v) => changeSupplier(v)} placeholder="Todos os fornecedores" searchable />
                        </div>
                        <div className="form-check mb-1 align-self-end">
                            <input className="form-check-input" type="checkbox" id="sd-missing-ref" checked={missingRef} onChange={(e) => changeMissingRef(e.target.checked)} />
                            <label className="form-check-label fs-13" htmlFor="sd-missing-ref">Só sem nº doc. fornecedor</label>
                        </div>
                        <div className="d-flex flex-wrap align-items-center gap-1" style={{ flex: "1 1 100%" }}>
                            <span className="fs-12 text-muted me-1">Tipo:</span>
                            {typeOptions.map((t) => {
                                const on = types.includes(t.id);
                                return (
                                    <button key={t.id} type="button" onClick={() => toggleType(t.id)} aria-pressed={on}
                                        className={`btn btn-sm ${on ? "btn-outline-primary active" : "btn-light text-muted"}`} style={{ borderRadius: 999 }}>
                                        {on && <i className="ri-check-line me-1" />}{t.label}{t.count !== undefined && <span className="ms-1 opacity-75">({t.count})</span>}
                                    </button>
                                );
                            })}
                            <button type="button" className="btn btn-sm btn-link fs-12" onClick={() => changeTypes(DEFAULT_TYPES)}>Repor</button>
                            <button type="button" className="btn btn-sm btn-link fs-12" onClick={() => changeTypes([])}>Todos os tipos</button>
                        </div>
                    </RestFilterBar>
                </>}
            >
                <DataTable
                    columns={cols}
                    data={rows}
                    rowKey={(r) => r.id}
                    mode={serverMode ? "server" : "client"}
                    loading={loading}
                    search={serverMode ? "" : search}
                    initialSort={{ id: "date", desc: true }}
                    rowClassName={(r) => (isVoided(r) ? "text-muted" : undefined)}
                    caption="Documentos de fornecedor no PingWin"
                    empty={{
                        message: "Sem documentos neste período.",
                        action: <Button color="outline-primary" size="sm" onClick={openSync} disabled={running}><i className="ri-refresh-line me-1" />Sincronizar</Button>,
                    }}
                    server={serverMode && meta ? {
                        page: meta.current_page, lastPage: meta.last_page, total: meta.total, perPage: meta.per_page,
                        from: meta.from ?? 0, to: meta.to ?? 0, onPageChange: setPage,
                    } : undefined}
                />
            </PageCard>

            <Modal isOpen={syncOpen} toggle={() => !syncBusy && setSyncOpen(false)} centered>
                <ModalHeader toggle={() => !syncBusy && setSyncOpen(false)}>Sincronizar documentos do PingWin</ModalHeader>
                <ModalBody>
                    <p className="text-muted fs-13 mb-3">
                        Lê do PingWin os documentos de fornecedor lançados no período (só leitura: nada é alterado no PingWin).
                        A madrugada já sincroniza os últimos 7 dias.
                    </p>
                    <Row className="g-2">
                        <Col md={6}>
                            <label className="form-label fs-12 mb-1" htmlFor="sd-sync-from">De</label>
                            <input id="sd-sync-from" type="date" className="form-control" value={syncFrom} max={syncTo || today()} onChange={(e) => setSyncFrom(e.target.value)} disabled={syncBusy} />
                        </Col>
                        <Col md={6}>
                            <label className="form-label fs-12 mb-1" htmlFor="sd-sync-to">Até</label>
                            <input id="sd-sync-to" type="date" className="form-control" value={syncTo} min={syncFrom || undefined} max={today()} onChange={(e) => setSyncTo(e.target.value)} disabled={syncBusy} />
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
