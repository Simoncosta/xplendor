import { useCallback, useEffect, useRef, useState } from "react";
import { Button, Container, Row, Col, Spinner, Nav, NavItem, NavLink, Label } from "reactstrap";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import classnames from "classnames";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import XSelect from "Components/Common/Select";
import RestFilterBar from "Components/Common/RestFilterBar";
import { getOcrInvoices, uploadOcrInvoice } from "helpers/laravel_helper";
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

function LinkBadge({ s }: { s: OcrLinkStatus | null }) {
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

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const first: any = await getOcrInvoices(companyId, { page: 1, perPage: API_PER_PAGE });
            const paginator = first?.data?.invoices;
            let all: InvoiceRow[] = paginator?.data ?? [];
            for (let p = 2; p <= (paginator?.last_page ?? 1); p++) {
                const next: any = await getOcrInvoices(companyId, { page: p, perPage: API_PER_PAGE });
                all = all.concat(next?.data?.invoices?.data ?? []);
            }
            setRows(all);
            setCap({ used: first?.data?.used_this_month ?? 0, cap: first?.data?.monthly_cap ?? 0 });
        } catch {
            setRows([]);
        } finally {
            setLoading(false);
        }
    }, [companyId]);

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
            toast.error(err?.message ?? "Não foi possível carregar a fatura.");
        } finally {
            setUploading(false);
        }
    };

    const columns: DTColumn<InvoiceRow>[] = [
        { id: "date", header: "Data", value: (r) => r.issue_date, cell: (r) => fmtDate(r.issue_date), nowrap: true, mobile: "subtitle" },
        { id: "number", header: "Documento", value: (r) => r.number, cell: (r) => <span className="fw-medium">{r.number || "—"}</span>, nowrap: true, mobile: "subtitle" },
        { id: "type", header: "Tipo", value: (r) => (r.doc_type ? DOC_TYPES[r.doc_type] ?? r.doc_type : null) },
        { id: "supplier", header: "Fornecedor", value: (r) => r.supplier_name, cell: (r) => r.supplier_name || <span className="text-muted">Por identificar</span>, mobile: "title" },
        { id: "nif", header: "NIF", value: (r) => r.supplier_nif, nowrap: true },
        { id: "total", header: "Total", value: (r) => r.total, cell: (r) => euro(r.total), align: "end", nowrap: true },
        { id: "status", header: "Estado", value: (r) => STATUS[r.status]?.label ?? r.status, cell: (r) => <StatusBadge s={r.status} />, align: "center" },
        { id: "qr", header: "QR", value: (r) => r.check_status, cell: (r) => <QrCheck r={r} />, align: "center" },
        { id: "pingwin", header: "PingWin", value: (r) => (r.link_status ? LINK_STATUS[r.link_status]?.label : null), cell: (r) => <LinkBadge s={r.link_status} />, align: "center" },
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
                                <input ref={fileRef} type="file" accept="image/*,application/pdf" className="d-none" onChange={onFile} />
                                <Button color="primary" onClick={onPickFile} disabled={uploading}>
                                    {uploading ? <><Spinner size="sm" className="me-1" /> A carregar…</> : <><i className="ri-upload-2-line me-1" /> Carregar fatura</>}
                                </Button>
                            </>}
                            filters={
                                <RestFilterBar
                                    search={search}
                                    onSearchChange={setSearch}
                                    searchPlaceholder="Pesquisar (fornecedor, NIF, documento)…"
                                    activeCount={statusFilter ? 1 : 0}
                                    onClear={() => { setSearch(""); setStatusFilter(""); }}
                                >
                                    <div style={{ flex: "1 1 180px", minWidth: 0 }}>
                                        <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>Estado</Label>
                                        <XSelect ariaLabel="Estado" small options={statusOptions} value={statusFilter} onChange={(v) => setStatusFilter(v)} searchable={false} placeholder="Todos" />
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
                                onRowClick={(r) => navigate(`/restauracao/faturas/${r.id}`)}
                                caption="Faturas carregadas"
                                empty={{
                                    message: statusFilter ? "Sem faturas neste estado." : "Ainda não carregou nenhuma fatura.",
                                    action: !statusFilter ? <Button color="outline-primary" size="sm" onClick={onPickFile}><i className="ri-upload-2-line me-1" />Carregar fatura</Button> : undefined,
                                }}
                                rowActions={(r) => (
                                    <Link to={`/restauracao/faturas/${r.id}`} className="btn btn-sm btn-outline-primary">
                                        {r.status === "validada" ? <><i className="ri-eye-line me-1" />Ver</> : <><i className="ri-check-double-line me-1" />Validar</>}
                                    </Link>
                                )}
                            />
                        </PageCard>
                    </Col>
                </Row>}
            </Container>
        </div>
    );
}
