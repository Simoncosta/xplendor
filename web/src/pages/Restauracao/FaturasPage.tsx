import { useCallback, useEffect, useRef, useState } from "react";
import { Button, Container, Row, Col, Spinner, Nav, NavItem, NavLink, Label, Input, Modal, ModalHeader, ModalBody, ModalFooter } from "reactstrap";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import classnames from "classnames";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import XSelect from "Components/Common/Select";
import RestFilterBar from "Components/Common/RestFilterBar";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import { uploadOcrInvoice, getOcrInvoicesList, deleteOcrInvoice, restoreOcrInvoice, bulkDeleteOcrInvoices } from "helpers/laravel_helper";
import { OcrInvoiceListRow, OcrInvoiceStatus, OcrLinkStatus, OCR_LINK_STATUS as LINK_STATUS } from "common/models/ocr.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import PingwinSupplierDocumentsTab from "./PingwinSupplierDocumentsTab";

/**
 * XPLENDOR — Restauração › Faturas (OCR). Carrega uma fatura de fornecedor (imagem/PDF) → a IA
 * lê (fila) → o utilizador VALIDA no ecrã de detalhe. ⚠️ NÃO escreve no PingWin. Só módulo
 * pingwin. Enquanto houver faturas "a processar", a lista faz polling leve.
 *
 * F1: separador "Documentos PingWin" — documentos de fornecedor lançados no PingWin (só
 * leitura). O separador ativo fica no URL (?tab=pingwin).
 *
 * UI-1: cada separador é um PageCard (ações e estado no cabeçalho) com um DataTable. A lista
 * lê todas as faturas (poucas por mês) e a tabela ordena, pesquisa e pagina no browser.
 *
 * F2c: apagar (fica 30 dias; "Mostrar apagadas" + "Repor"), apagar várias (seleção) e o mesmo
 * ficheiro recusado no carregamento ("Já carregada: abrir a fatura #N").
 *
 * F3: coluna "PingWin" (a fatura está lançada? por que documento?). Liquidado e Loja vêm do
 * documento do PingWin ligado; o Tipo vem do QR.
 */

const fmtDate = (d?: string | null) => (d ? new Date(d).toLocaleDateString("pt-PT") : "—");
const euro = (n?: number | null) => (n === null || n === undefined ? "—" : n.toLocaleString("pt-PT", { style: "currency", currency: "EUR" }));

const STATUS: Record<OcrInvoiceStatus, { label: string; cls: string }> = {
    processing: { label: "A processar", cls: "bg-info-subtle text-info" },
    por_validar: { label: "Por validar", cls: "bg-warning-subtle text-warning" },
    validada: { label: "Validada", cls: "bg-success-subtle text-success" },
    erro: { label: "Erro", cls: "bg-danger-subtle text-danger" },
    nao_desta_empresa: { label: "Não é desta empresa", cls: "bg-secondary-subtle text-secondary" },
};

/** Tipos de documento do QR da AT (campo D). */
const DOC_TYPES: Record<string, string> = {
    FT: "Fatura", FS: "Fatura simplificada", FR: "Fatura-recibo", NC: "Nota de crédito", ND: "Nota de débito",
    VD: "Venda a dinheiro", GT: "Guia de transporte", GR: "Guia de remessa", RC: "Recibo",
};

type InvoiceRow = OcrInvoiceListRow;

function LinkBadge({ s, docStatus }: { s: OcrLinkStatus | null; docStatus?: string | null }) {
    // FB-1: lançada pela XPLENDOR → o estado do documento no PingWin.
    if (docStatus === "8001") return <span className="badge bg-info-subtle text-info" title="Lançada pela XPLENDOR em rascunho (Aberto): ainda não mexe no stock">Rascunho</span>;
    if (docStatus === "8002") return <span className="badge bg-success-subtle text-success" title="Lançada pela XPLENDOR e fechada no PingWin">Lançada</span>;
    if (!s) return <span className="text-muted">—</span>;
    const st = LINK_STATUS[s];
    return <span className={`badge ${st.cls}`} title={st.title}>{st.label}</span>;
}

const SOURCE: Record<string, string> = { "qr+texto": "QR e texto do PDF", "qr+imagem": "QR e imagem", sem_qr: "Sem QR (só IA)", qr: "QR" };


function StatusBadge({ s }: { s: OcrInvoiceStatus }) {
    const st = STATUS[s] ?? { label: s, cls: "bg-secondary-subtle text-secondary" };
    return (
        <span className={`badge ${st.cls}`}>
            {s === "processing" && <Spinner size="sm" style={{ width: 10, height: 10 }} className="me-1" />}
            {st.label}
        </span>
    );
}

function QrCheck({ r }: { r: InvoiceRow }) {
    if (r.check_status === "confere") return <span className="badge bg-success-subtle text-success" title="As linhas batem com o QR da fatura"><i className="ri-check-line me-1" />Confere</span>;
    if (r.check_status === "nao_confere") return <span className="badge bg-warning-subtle text-warning" title="As linhas não batem com o QR da fatura: veja no detalhe"><i className="ri-error-warning-line me-1" />Diferença</span>;
    if (r.check_status === "sem_qr") return <span className="text-muted" title="A fatura não tem QR legível">Sem QR</span>;
    return <span className="text-muted">—</span>;
}

type StatusFilter = "" | OcrInvoiceStatus;
const statusOptions: { value: StatusFilter; label: string }[] = [
    { value: "", label: "Todos" },
    ...(Object.keys(STATUS) as OcrInvoiceStatus[]).map((k) => ({ value: k as StatusFilter, label: STATUS[k].label })),
];

const API_PER_PAGE = 100;

export default function FaturasPage() {
    document.title = "Faturas | Restauração | Xplendor";
    const navigate = useNavigate();
    const fileRef = useRef<HTMLInputElement>(null);

    const companyId = useWorkingCompanyId();
    const [searchParams, setSearchParams] = useSearchParams();
    const tab: "ocr" | "pingwin" = searchParams.get("tab") === "pingwin" ? "pingwin" : "ocr";
    const setTab = (t: "ocr" | "pingwin") => setSearchParams(t === "ocr" ? {} : { tab: t }, { replace: true });

    const [rows, setRows] = useState<InvoiceRow[]>([]);
    const [cap, setCap] = useState<{ used: number; cap: number } | null>(null);
    const [loading, setLoading] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [search, setSearch] = useState("");
    const [statusFilter, setStatusFilter] = useState<StatusFilter>("");
    // F2c — apagar / repor / apagar várias
    const [showDeleted, setShowDeleted] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [deleteOne, setDeleteOne] = useState<InvoiceRow | null>(null);
    const [bulkOpen, setBulkOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const del = showDeleted ? { deleted: 1 as const } : {};
            const first: any = await getOcrInvoicesList(companyId, { page: 1, perPage: API_PER_PAGE, ...del });
            const paginator = first?.data?.invoices;
            let all: InvoiceRow[] = paginator?.data ?? [];
            for (let p = 2; p <= (paginator?.last_page ?? 1); p++) {
                const next: any = await getOcrInvoicesList(companyId, { page: p, perPage: API_PER_PAGE, ...del });
                all = all.concat(next?.data?.invoices?.data ?? []);
            }
            setRows(all);
            setSelected((prev) => new Set(Array.from(prev).filter((id) => all.some((r) => r.id === id))));
            setCap({ used: first?.data?.used_this_month ?? 0, cap: first?.data?.monthly_cap ?? 0 });
        } catch {
            setRows([]);
        } finally {
            setLoading(false);
        }
    }, [companyId, showDeleted]);

    useEffect(() => { if (tab === "ocr") fetchRows(); }, [fetchRows, tab]);

    // Polling leve enquanto houver faturas "a processar".
    useEffect(() => {
        if (tab !== "ocr" || !rows.some((r) => r.status === "processing")) return;
        const t = setInterval(fetchRows, 4000);
        return () => clearInterval(t);
    }, [rows, fetchRows, tab]);

    const onPickFile = () => fileRef.current?.click();

    const onFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = ""; // permite re-selecionar o mesmo ficheiro
        if (!file || !companyId) return;
        setUploading(true);
        try {
            await uploadOcrInvoice(companyId, file);
            toast.info("A ler a fatura… será notificado no sino quando terminar.");
            await fetchRows();
        } catch (err: any) {
            if (err?.__status === 409 && err?.errors?.code === "ficheiro_duplicado") {
                const id = err.errors.existing_id;
                toast.error(<span>Já carregada: <button type="button" className="btn btn-link p-0 align-baseline" onClick={() => navigate(`/restauracao/faturas/${id}`)}>abrir a fatura #{id}</button></span>);
            } else {
                toast.error(err?.message ?? "Não foi possível carregar a fatura.");
            }
        } finally {
            setUploading(false);
        }
    };

    // ── F2c: apagar / repor / apagar várias ────────────────────────────────
    const toggle = (id: number) => setSelected((prev) => {
        const next = new Set(prev);
        if (next.has(id)) next.delete(id); else next.add(id);
        return next;
    });
    const doDeleteOne = async () => {
        if (!deleteOne) return;
        setBusy(true);
        try {
            await deleteOcrInvoice(companyId, deleteOne.id);
            toast.success("Fatura apagada. Pode repô-la durante 30 dias em “Mostrar apagadas”.");
            setDeleteOne(null);
            await fetchRows();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível apagar a fatura.");
        } finally {
            setBusy(false);
        }
    };
    const doBulkDelete = async () => {
        setBusy(true);
        try {
            const r: any = await bulkDeleteOcrInvoices(companyId, Array.from(selected));
            const skipped = r?.data?.skipped ?? [];
            toast.success(`${r?.data?.deleted?.length ?? 0} fatura(s) apagada(s).`);
            if (skipped.length) toast.warning(<span>Ficaram de fora:<ul className="mb-0 ps-3">{skipped.map((x: any) => <li key={x.id}>{x.number ?? `#${x.id}`}: {x.reason}</li>)}</ul></span>, { autoClose: 10000 });
            setSelected(new Set());
            setBulkOpen(false);
            await fetchRows();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível apagar as faturas.");
        } finally {
            setBusy(false);
        }
    };
    const doRestore = async (r: InvoiceRow) => {
        setBusy(true);
        try {
            await restoreOcrInvoice(companyId, r.id);
            toast.success("Fatura reposta.");
            await fetchRows();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível repor a fatura.");
        } finally {
            setBusy(false);
        }
    };

    const selectCol: DTColumn<InvoiceRow> = {
        id: "select", header: "Selecionar", hideable: false, mobile: "hide", sortable: false, className: "text-center", cellClassName: () => "text-center",
        cell: (r) => <input type="checkbox" className="form-check-input mt-0" aria-label={`Selecionar a fatura ${r.number ?? r.id}`} checked={selected.has(r.id)}
            onClick={(e) => e.stopPropagation()} onChange={() => toggle(r.id)} />,
    };
    const deletedCol: DTColumn<InvoiceRow> = {
        id: "deleted_at", header: "Apagada em", value: (r) => r.deleted_at, cell: (r) => fmtDate(r.deleted_at), nowrap: true, hideable: false,
    };
    const columns: DTColumn<InvoiceRow>[] = [
        ...(showDeleted ? [deletedCol] : [selectCol]),
        { id: "date", header: "Data", value: (r) => r.issue_date, cell: (r) => fmtDate(r.issue_date), nowrap: true, mobile: "subtitle" },
        { id: "number", header: "Documento", value: (r) => r.number, cell: (r) => <span className="fw-medium">{r.number || "—"}</span>, nowrap: true, mobile: "subtitle" },
        { id: "type", header: "Tipo", value: (r) => (r.doc_type ? DOC_TYPES[r.doc_type] ?? r.doc_type : null) },
        { id: "supplier", header: "Fornecedor", value: (r) => r.supplier_name, cell: (r) => r.supplier_name || <span className="text-muted">Por identificar</span>, mobile: "title" },
        { id: "nif", header: "NIF", value: (r) => r.supplier_nif, nowrap: true },
        { id: "total", header: "Total", value: (r) => r.total, cell: (r) => euro(r.total), align: "end", nowrap: true },
        { id: "status", header: "Estado", value: (r) => STATUS[r.status]?.label ?? r.status, cell: (r) => <StatusBadge s={r.status} />, align: "center" },
        { id: "qr", header: "QR", value: (r) => r.check_status, cell: (r) => <QrCheck r={r} />, align: "center" },
        { id: "pingwin", header: "PingWin", value: (r) => (r.pingwin_doc_status === "8001" ? "Rascunho" : r.pingwin_doc_status === "8002" ? "Lançada" : r.link_status ? LINK_STATUS[r.link_status]?.label : null), cell: (r) => <LinkBadge s={r.link_status} docStatus={r.pingwin_doc_status} />, align: "center" },
        {
            id: "paid", header: "Liquidado", value: (r) => (r.paid === null ? null : r.paid ? 1 : 0), align: "center",
            cell: (r) => (r.paid === null ? <span className="text-muted" title="Sem documento do PingWin ligado">—</span>
                : r.paid ? <span className="badge bg-success-subtle text-success">Sim</span> : <span className="text-muted">Não</span>),
        },
        { id: "store", header: "Loja", value: (r) => r.store },
        { id: "confidence", header: "Confiança", value: (r) => r.confidence || null, cell: (r) => (r.confidence ? `${r.confidence}%` : "—"), align: "center", defaultVisible: false },
        { id: "source", header: "Leitura", value: (r) => (r.source ? SOURCE[r.source] ?? r.source : null), defaultVisible: false },
    ];
    const cols = useDataColumns("restauracao.faturas.carregadas", columns);

    const shown = statusFilter ? rows.filter((r) => r.status === statusFilter) : rows;
    const sel = rows.filter((r) => selected.has(r.id));
    const selDeletable = sel.filter((r) => !r.delete_block);
    const allSelected = shown.length > 0 && shown.every((r) => selected.has(r.id));

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title="Faturas"
                    breadcrumbs={[{ label: "Restauração" }]}
                    info="As faturas de fornecedor: as que carrega para a IA ler e validar, e as que já estão lançadas no PingWin."
                />

                <Nav tabs className="nav-tabs-custom mb-3">
                    <NavItem><NavLink className={classnames({ active: tab === "ocr" })} onClick={() => setTab("ocr")} style={{ cursor: "pointer" }}>Faturas carregadas</NavLink></NavItem>
                    <NavItem><NavLink className={classnames({ active: tab === "pingwin" })} onClick={() => setTab("pingwin")} style={{ cursor: "pointer" }}>Documentos PingWin</NavLink></NavItem>
                </Nav>

                {tab === "pingwin" && (
                    <Row><Col xs={12}><PingwinSupplierDocumentsTab companyId={companyId} /></Col></Row>
                )}

                {tab === "ocr" && <Row>
                    <Col xs={12}>
                        <PageCard
                            title="Faturas carregadas"
                            info="Faturas de fornecedor lidas com IA, para validar aqui. Não são enviadas ao PingWin."
                            status={cap && cap.cap > 0 ? <>{cap.used} de {cap.cap} leituras este mês</> : undefined}
                            loading={loading && rows.length > 0}
                            actions={<>
                                {cols.selector}
                                {!showDeleted && shown.length > 0 && (
                                    <Button size="sm" color="outline-primary" onClick={() => setSelected(allSelected ? new Set() : new Set(shown.map((r) => r.id)))}>
                                        {allSelected ? "Desmarcar todas" : "Selecionar todas"}
                                    </Button>
                                )}
                                {!showDeleted && (
                                    <ActionsMenu size="sm" label="Mais ações das faturas" items={[
                                        { label: `Apagar selecionadas${sel.length ? ` (${sel.length})` : ""}`, icon: "ri-delete-bin-line", danger: true,
                                            disabledReason: sel.length === 0 ? "Selecione faturas na tabela." : null, onClick: () => setBulkOpen(true) },
                                    ]} />
                                )}
                                <input ref={fileRef} type="file" accept="image/*,application/pdf" className="d-none" onChange={onFile} />
                                <Button size="sm" color="primary" onClick={onPickFile} disabled={uploading}>
                                    {uploading ? <><Spinner size="sm" className="me-1" /> A carregar…</> : <><i className="ri-upload-2-line me-1" /> Carregar fatura</>}
                                </Button>
                            </>}
                            filters={
                                <RestFilterBar
                                    search={search}
                                    onSearchChange={setSearch}
                                    searchPlaceholder="Pesquisar (fornecedor, NIF, documento)…"
                                    activeCount={(statusFilter ? 1 : 0) + (showDeleted ? 1 : 0)}
                                    onClear={() => { setSearch(""); setStatusFilter(""); setShowDeleted(false); }}
                                >
                                    <div style={{ flex: "1 1 180px", minWidth: 0 }}>
                                        <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>Estado</Label>
                                        <XSelect ariaLabel="Estado" small options={statusOptions} value={statusFilter} onChange={(v) => setStatusFilter(v)} searchable={false} placeholder="Todos" />
                                    </div>
                                    <div className="d-flex align-items-end" style={{ flex: "0 0 auto" }}>
                                        <div className="form-check form-switch mb-1">
                                            <Input type="switch" role="switch" id="show-deleted" className="form-check-input" checked={showDeleted}
                                                onChange={(e) => { setShowDeleted(e.target.checked); setSelected(new Set()); }} />
                                            <Label className="form-check-label fs-13" for="show-deleted">Mostrar apagadas</Label>
                                        </div>
                                    </div>
                                </RestFilterBar>
                            }
                        >
                            <DataTable
                                columns={cols}
                                data={shown}
                                rowKey={(r) => r.id}
                                loading={loading}
                                search={search}
                                initialSort={{ id: "date", desc: true }}
                                onRowClick={showDeleted ? undefined : (r) => navigate(`/restauracao/faturas/${r.id}`)}
                                caption={showDeleted ? "Faturas apagadas" : "Faturas carregadas"}
                                empty={{
                                    message: showDeleted ? "Não há faturas apagadas." : statusFilter ? "Sem faturas neste estado." : "Ainda não carregou nenhuma fatura.",
                                    action: !statusFilter ? <Button color="outline-primary" size="sm" onClick={onPickFile}><i className="ri-upload-2-line me-1" />Carregar fatura</Button> : undefined,
                                }}
                                rowActions={(r) => showDeleted ? (
                                    <ReasonButton size="sm" color="outline-primary" reason={r.restore_block} disabled={busy} onClick={() => doRestore(r)}>
                                        <i className="ri-arrow-go-back-line me-1" />Repor
                                    </ReasonButton>
                                ) : (
                                    <div className="d-flex gap-1 justify-content-end">
                                        <Link to={`/restauracao/faturas/${r.id}`} className="btn btn-sm btn-outline-primary">
                                            {r.status === "validada" ? <><i className="ri-eye-line me-1" />Ver</> : <><i className="ri-check-double-line me-1" />Validar</>}
                                        </Link>
                                        <ActionsMenu size="sm" label={`Mais ações: fatura ${r.number ?? r.id}`} items={[
                                            { label: "Apagar", icon: "ri-delete-bin-line", danger: true, disabledReason: r.delete_block, onClick: () => setDeleteOne(r) },
                                        ]} />
                                    </div>
                                )}
                            />
                        </PageCard>
                    </Col>
                </Row>}

                {/* F2c — confirmações */}
                <Modal isOpen={!!deleteOne} toggle={() => !busy && setDeleteOne(null)} centered data-testid="delete-modal">
                    <ModalHeader toggle={() => !busy && setDeleteOne(null)}>Apagar a fatura {deleteOne?.number ?? `#${deleteOne?.id}`}?</ModalHeader>
                    <ModalBody>
                        <p className="mb-2">A fatura sai da lista. Pode repô-la durante 30 dias em “Mostrar apagadas”; depois o ficheiro é apagado.</p>
                        {deleteOne && (deleteOne.link_status === "lancada" || deleteOne.link_status === "lancada_guias") && (
                            <p className="mb-2 fs-13 text-muted">Está ligada a um documento no PingWin: o documento no PingWin fica; só a fatura carregada é apagada e desligada.</p>
                        )}
                        <p className="mb-0 fs-13 text-muted">A leitura não volta ao limite mensal.</p>
                    </ModalBody>
                    <ModalFooter>
                        <Button color="light" onClick={() => setDeleteOne(null)} disabled={busy}>Cancelar</Button>
                        <Button color="danger" onClick={doDeleteOne} disabled={busy}>{busy ? <Spinner size="sm" /> : "Apagar"}</Button>
                    </ModalFooter>
                </Modal>
                <Modal isOpen={bulkOpen} toggle={() => !busy && setBulkOpen(false)} centered data-testid="bulk-delete-modal">
                    <ModalHeader toggle={() => !busy && setBulkOpen(false)}>Apagar {selDeletable.length} fatura(s)?</ModalHeader>
                    <ModalBody>
                        <p className="mb-2">Saem da lista e podem ser repostas durante 30 dias em “Mostrar apagadas”. A leitura não volta ao limite mensal.</p>
                        {sel.length > selDeletable.length && (
                            <>
                                <p className="mb-1 fs-13 fw-semibold">Ficam de fora:</p>
                                <ul className="fs-13 mb-0 ps-3">
                                    {sel.filter((r) => r.delete_block).map((r) => <li key={r.id}>{r.number ?? `#${r.id}`}: {r.delete_block}</li>)}
                                </ul>
                            </>
                        )}
                    </ModalBody>
                    <ModalFooter>
                        <Button color="light" onClick={() => setBulkOpen(false)} disabled={busy}>Cancelar</Button>
                        <ReasonButton color="danger" reason={selDeletable.length === 0 ? "Nenhuma das selecionadas se pode apagar." : null} disabled={busy} onClick={doBulkDelete}>
                            {busy ? <Spinner size="sm" /> : `Apagar ${selDeletable.length}`}
                        </ReasonButton>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
}
