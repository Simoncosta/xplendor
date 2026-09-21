import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Card, Container, Row, Col, Spinner } from "reactstrap";
import { Link, useNavigate } from "react-router-dom";
import { toast, ToastContainer } from "react-toastify";
import { useIsMobile } from "../../hooks/useIsMobile";
import Pagination from "Components/Common/Pagination";
import { getOcrInvoices, uploadOcrInvoice } from "helpers/laravel_helper";
import { OcrInvoiceListRow, OcrInvoiceStatus } from "common/models/ocr.model";
import { LaravelPaginator } from "common/models/pingwin.model";

/**
 * XPLENDOR — Restauração › Faturas (OCR, Fase A). Carrega uma fatura de fornecedor
 * (imagem/PDF) → a IA lê (fila) → o utilizador VALIDA no ecrã de detalhe. ⚠️ NÃO
 * escreve no PingWin. Só módulo pingwin. Enquanto houver faturas "a processar",
 * a lista faz polling leve.
 */

const fmtDate = (d?: string | null) => (d ? new Date(d).toLocaleDateString("pt-PT") : "—");
const euro = (n?: number | null) => (n === null || n === undefined ? "—" : n.toLocaleString("pt-PT", { style: "currency", currency: "EUR" }));

const STATUS: Record<OcrInvoiceStatus, { label: string; cls: string }> = {
    processing: { label: "A processar", cls: "bg-info-subtle text-info" },
    por_validar: { label: "Por validar", cls: "bg-warning-subtle text-warning" },
    validada: { label: "Validada", cls: "bg-success-subtle text-success" },
    erro: { label: "Erro", cls: "bg-danger-subtle text-danger" },
};

const PER_PAGE = 20;

export default function FaturasPage() {
    document.title = "Faturas | Restauração | Xplendor";
    const isMobile = useIsMobile();
    const navigate = useNavigate();
    const fileRef = useRef<HTMLInputElement>(null);

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

    const [page, setPage] = useState(1);
    const [meta, setMeta] = useState<Omit<LaravelPaginator<OcrInvoiceListRow>, "data"> | null>(null);
    const [rows, setRows] = useState<OcrInvoiceListRow[]>([]);
    const [cap, setCap] = useState<{ used: number; cap: number } | null>(null);
    const [loading, setLoading] = useState(false);
    const [uploading, setUploading] = useState(false);

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getOcrInvoices(companyId, { page, perPage: PER_PAGE });
            const paginator = res?.data?.invoices;
            setRows(paginator?.data ?? []);
            const { data: _omit, ...m } = paginator ?? {};
            setMeta(paginator ? (m as any) : null);
            setCap({ used: res?.data?.used_this_month ?? 0, cap: res?.data?.monthly_cap ?? 0 });
        } catch {
            setRows([]);
        } finally {
            setLoading(false);
        }
    }, [companyId, page]);

    useEffect(() => { fetchRows(); }, [fetchRows]);

    // Polling leve enquanto houver faturas "a processar".
    useEffect(() => {
        if (!rows.some((r) => r.status === "processing")) return;
        const t = setInterval(fetchRows, 4000);
        return () => clearInterval(t);
    }, [rows, fetchRows]);

    const onPickFile = () => fileRef.current?.click();

    const onFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = ""; // permite re-selecionar o mesmo ficheiro
        if (!file || !companyId) return;
        setUploading(true);
        try {
            await uploadOcrInvoice(companyId, file);
            toast.info("A ler a fatura… vais ser notificado no sino quando terminar.");
            setPage(1);
            await fetchRows();
        } catch (err: any) {
            toast.error(err?.message ?? "Não foi possível carregar a fatura.");
        } finally {
            setUploading(false);
        }
    };

    const StatusBadge = ({ s }: { s: OcrInvoiceStatus }) => (
        <span className={`badge ${STATUS[s].cls}`}>
            {s === "processing" && <Spinner size="sm" style={{ width: 10, height: 10 }} className="me-1" />}
            {STATUS[s].label}
        </span>
    );

    const goTo = (r: OcrInvoiceListRow) => navigate(`/restauracao/faturas/${r.id}`);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row>
                    <Col xs={12}>
                        <div className="page-title-box d-sm-flex align-items-center justify-content-between">
                            <div>
                                <h4 className="mb-sm-0">Faturas</h4>
                                <small className="text-muted">
                                    Lê faturas de fornecedor com IA e valida-as. Não são enviadas ao PingWin.
                                    {cap && <> · {cap.used}/{cap.cap} este mês</>}
                                </small>
                            </div>
                            <div>
                                <input ref={fileRef} type="file" accept="image/*,application/pdf" className="d-none" onChange={onFile} />
                                <button className="btn btn-primary" onClick={onPickFile} disabled={uploading}>
                                    {uploading ? <><Spinner size="sm" className="me-1" /> A carregar…</> : <><i className="ri-upload-2-line me-1" /> Carregar fatura</>}
                                </button>
                            </div>
                        </div>
                    </Col>
                </Row>

                <Row>
                    <Col xs={12}>
                        <Card className="mb-3">
                            <div className="card-header">
                                <h5 className="card-title mb-0">Faturas carregadas {loading && <Spinner size="sm" className="ms-1" />}</h5>
                            </div>

                            {isMobile ? (
                                <div className="p-3 d-flex flex-column gap-2">
                                    {!loading && rows.length === 0 ? (
                                        <div className="text-center text-muted py-4">Sem faturas. Usa <strong>“Carregar fatura”</strong>.</div>
                                    ) : rows.map((r) => (
                                        <div key={r.id} role="button" onClick={() => goTo(r)}
                                            style={{ border: "1px solid var(--vz-border-color)", borderRadius: 12, padding: "12px 14px", background: "var(--vz-card-bg)", cursor: "pointer" }}>
                                            <div className="d-flex align-items-start justify-content-between gap-2">
                                                <div style={{ minWidth: 0 }}>
                                                    <div className="fw-semibold text-body text-truncate">{r.supplier_name || "Fornecedor por identificar"}</div>
                                                    <div className="text-muted fs-12">{r.number || "—"} · {fmtDate(r.issue_date)}</div>
                                                </div>
                                                <StatusBadge s={r.status} />
                                            </div>
                                            <div className="d-flex justify-content-between mt-2">
                                                <span className="fw-semibold">{euro(r.total)}</span>
                                                <span className="text-muted fs-12">{r.confidence ? `${r.confidence}% confiança` : ""}</span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="table-responsive">
                                    <table className="table table-bordered table-hover align-middle mb-0">
                                        <thead className="text-muted table-light">
                                            <tr>
                                                <th>Fornecedor</th>
                                                <th>Nº</th>
                                                <th>Data</th>
                                                <th className="text-end">Total</th>
                                                <th className="text-center">Confiança</th>
                                                <th className="text-center">Estado</th>
                                                <th className="text-end">Ação</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {!loading && rows.length === 0 ? (
                                                <tr><td colSpan={7} className="text-center text-muted py-4">Sem faturas. Usa <strong>“Carregar fatura”</strong>.</td></tr>
                                            ) : rows.map((r) => (
                                                <tr key={r.id}>
                                                    <td className="fw-medium">{r.supplier_name || <span className="text-muted">Por identificar</span>}</td>
                                                    <td>{r.number || "—"}</td>
                                                    <td>{fmtDate(r.issue_date)}</td>
                                                    <td className="text-end">{euro(r.total)}</td>
                                                    <td className="text-center">{r.confidence ? `${r.confidence}%` : "—"}</td>
                                                    <td className="text-center"><StatusBadge s={r.status} /></td>
                                                    <td className="text-end">
                                                        <Link to={`/restauracao/faturas/${r.id}`} className="btn btn-sm btn-soft-primary">
                                                            {r.status === "validada" ? <><i className="ri-eye-line me-1" />Ver</> : <><i className="ri-check-double-line me-1" />Validar</>}
                                                        </Link>
                                                    </td>
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
                    </Col>
                </Row>
            </Container>
        </div>
    );
}
