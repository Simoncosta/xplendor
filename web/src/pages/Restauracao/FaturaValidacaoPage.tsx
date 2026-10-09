import { useCallback, useEffect, useMemo, useState } from "react";
import { Button, Card, CardBody, Container, Row, Col, Spinner, Label, Alert, Modal, ModalHeader, ModalBody, ModalFooter } from "reactstrap";
import { useNavigate, useParams } from "react-router-dom";
import { toast, ToastContainer } from "react-toastify";
import Select from "react-select";
import { reactSelectTheme } from "../../helpers/reactSelectStyles";
import PageHeader, { Crumb } from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import {
    getOcrInvoice, updateOcrInvoice, getOcrInvoiceImageBlob, reprocessOcrInvoice,
    searchOcrPingwinLink, confirmOcrPingwinLink, unlinkOcrPingwinLink,
} from "helpers/laravel_helper";
import {
    OcrInvoiceDetail, OcrInvoiceLine, OcrInvoiceSummary, OcrVatBreakdownRow, OcrSupplierOption,
    OcrPingwinBlock, OcrPingwinDoc, OcrLinkMethod, OCR_LINK_STATUS,
} from "common/models/ocr.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

/**
 * XPLENDOR — Restauração › Faturas › Validação (OCR Fase A, o CORE). Mostra o que
 * a IA leu (linhas + sumário) EDITÁVEL, com a imagem ao lado para conferir, e
 * avisa quando os totais não fecham (não bloqueia — o utilizador decide). Ao
 * guardar, valida NA XPLENDOR. ⚠️ NÃO escreve no PingWin.
 *
 * F2a: com QR da AT o cabeçalho (NIFs, nº, data, ATCUD) vem do QR — só leitura — e as
 * linhas são conferidas por taxa de IVA contra as bases do QR. "Reprocessar" volta a ler
 * (async, mesmo polling).
 *
 * F3: bloco "No PingWin" — o documento lançado no PingWin a que a fatura está ligada (ou os
 * candidatos, ou o modo guias com seleção e soma). Só lê os espelhos: nada é gravado no PingWin.
 */

const VAT_OPTS = [0, 6, 13, 23].map((v) => ({ value: v, label: v === 0 ? "Isento" : `${v}%` }));
const num = (v: any): number | null => (v === "" || v === null || v === undefined || isNaN(Number(v)) ? null : Number(v));
const eur = (n?: number | null) => (n ?? 0).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });
const approx = (a: number, b: number) => Math.abs(a - b) <= Math.max(0.05, Math.abs(b) * 0.01);

const emptyLine = (): OcrInvoiceLine => ({ supplier_code: "", item: "", quantity: null, unit: "", unit_price: null, discount_pct: null, line_total: null, vat_rate: null });
const emptySummary = (): OcrInvoiceSummary => ({ goods_total: 0, commercial_discount: 0, taxable_base: 0, vat_total: 0, withholding: 0, financial_discount: 0, total: 0, vat_breakdown: [] });

export default function FaturaValidacaoPage() {
    document.title = "Validar fatura | Restauração | Xplendor";
    const navigate = useNavigate();
    const { id } = useParams();
    const invoiceId = Number(id);

    const companyId = useWorkingCompanyId();

    const [inv, setInv] = useState<OcrInvoiceDetail | null>(null);
    const [suppliers, setSuppliers] = useState<OcrSupplierOption[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [imageUrl, setImageUrl] = useState<string | null>(null);
    const [imageIsPdf, setImageIsPdf] = useState(false);
    const [confirmReprocess, setConfirmReprocess] = useState(false);
    const [reprocessing, setReprocessing] = useState(false);
    const [pingwin, setPingwin] = useState<OcrPingwinBlock | null>(null);

    // Estado editável
    const [supplierId, setSupplierId] = useState<number | null>(null);
    const [supplierName, setSupplierName] = useState("");
    const [supplierNif, setSupplierNif] = useState("");
    const [number, setNumber] = useState("");
    const [issueDate, setIssueDate] = useState("");
    const [lines, setLines] = useState<OcrInvoiceLine[]>([]);
    const [summary, setSummary] = useState<OcrInvoiceSummary>(emptySummary());

    const hydrate = useCallback((d: OcrInvoiceDetail) => {
        setInv(d);
        setSupplierId(d.supplier_id);
        setSupplierName(d.supplier_name ?? "");
        setSupplierNif(d.supplier_nif ?? "");
        setNumber(d.number ?? "");
        setIssueDate(d.issue_date ?? "");
        setLines(d.lines?.length ? d.lines : []);
        setSummary(d.summary ?? emptySummary());
    }, []);

    const fetchInvoice = useCallback(async () => {
        if (!companyId || !invoiceId) return;
        try {
            const res: any = await getOcrInvoice(companyId, invoiceId);
            setSuppliers(res?.data?.suppliers ?? []);
            setPingwin(res?.data?.pingwin ?? null);
            const d: OcrInvoiceDetail = res?.data?.invoice;
            if (d) hydrate(d);
        } catch {
            toast.error("Não foi possível carregar a fatura.");
        } finally {
            setLoading(false);
        }
    }, [companyId, invoiceId, hydrate]);

    useEffect(() => { fetchInvoice(); }, [fetchInvoice]);

    // Poll enquanto a IA processa.
    useEffect(() => {
        if (inv?.status !== "processing") return;
        const t = setInterval(fetchInvoice, 3000);
        return () => clearInterval(t);
    }, [inv?.status, fetchInvoice]);

    // F3: só o bloco "No PingWin" (sem tocar no formulário) — polling da pesquisa no worker.
    const refreshPingwin = useCallback(async () => {
        if (!companyId || !invoiceId) return;
        try {
            const res: any = await getOcrInvoice(companyId, invoiceId);
            setPingwin(res?.data?.pingwin ?? null);
        } catch { /* fica o que está */ }
    }, [companyId, invoiceId]);

    useEffect(() => {
        if (!pingwin?.search_pending) return;
        const t = setInterval(refreshPingwin, 3000);
        return () => clearInterval(t);
    }, [pingwin?.search_pending, refreshPingwin]);

    // Imagem (disco privado → blob).
    useEffect(() => {
        if (!companyId || !invoiceId || inv?.status === "processing") return;
        let revoked: string | null = null;
        getOcrInvoiceImageBlob(companyId, invoiceId)
            .then((blob: any) => {
                const b = blob as Blob;
                setImageIsPdf((b.type || "").includes("pdf"));
                const u = URL.createObjectURL(b);
                revoked = u;
                setImageUrl(u);
            })
            .catch(() => setImageUrl(null));
        return () => { if (revoked) URL.revokeObjectURL(revoked); };
    }, [companyId, invoiceId, inv?.status]);

    const supplierOptions = useMemo(
        () => suppliers.map((s) => ({ value: s.id, label: `${s.name ?? "—"}${s.tax_number ? ` · ${s.tax_number}` : ""}` })),
        [suppliers]
    );

    const setLine = (i: number, patch: Partial<OcrInvoiceLine>) =>
        setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));
    const setSum = (patch: Partial<OcrInvoiceSummary>) => setSummary((prev) => ({ ...prev, ...patch }));
    const setVat = (i: number, patch: Partial<OcrVatBreakdownRow>) =>
        setSummary((prev) => ({ ...prev, vat_breakdown: prev.vat_breakdown.map((b, idx) => (idx === i ? { ...b, ...patch } : b)) }));

    // ⚠️ A Xplendor calcula a soma das linhas (mais fiável que o sumário da IA).
    // Recalcula ao vivo quando o utilizador edita uma linha.
    const linesSum = useMemo(() => lines.reduce((a, l) => a + (l.line_total ?? 0), 0), [lines]);
    const round2 = (n: number) => Math.round(n * 100) / 100;

    // Avisos de coerência (não bloqueiam). Com QR a conferência por taxa (abaixo) substitui
    // o aviso "linhas vs base".
    const hasQr = !!inv?.qr_ok;
    const warnings = useMemo(() => {
        const w: string[] = [];
        if (!hasQr && lines.length > 0 && !approx(linesSum, summary.taxable_base))
            w.push(`As linhas somam ${eur(linesSum)}, mas o sumário da IA diz base tributável ${eur(summary.taxable_base)}. Confira.`);
        if (!approx(summary.goods_total - summary.commercial_discount, summary.taxable_base))
            w.push(`Mercadorias − desconto comercial (${eur(summary.goods_total - summary.commercial_discount)}) ≠ base tributável (${eur(summary.taxable_base)}).`);
        const chain = summary.taxable_base + summary.vat_total - summary.withholding - summary.financial_discount;
        if (!approx(chain, summary.total))
            w.push(`Base + IVA − retenção − desc. financeiro (${eur(chain)}) ≠ total (${eur(summary.total)}).`);
        return w;
    }, [hasQr, lines.length, linesSum, summary]);

    // Reprocessar (async): a fatura volta a 'processing' e o polling acima faz o resto.
    const reprocess = async () => {
        setReprocessing(true);
        try {
            await reprocessOcrInvoice(companyId, invoiceId);
            setConfirmReprocess(false);
            setInv((prev) => (prev ? { ...prev, status: "processing" } : prev));
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível reprocessar a fatura.");
        } finally {
            setReprocessing(false);
        }
    };

    const save = async () => {
        setSaving(true);
        try {
            await updateOcrInvoice(companyId, invoiceId, {
                supplier_id: supplierId, supplier_name: supplierName || null, supplier_nif: supplierNif || null,
                number: number || null, issue_date: issueDate || null,
                lines: lines.map((l) => ({
                    supplier_code: l.supplier_code || null, item: l.item || null, quantity: num(l.quantity), unit: l.unit || null,
                    unit_price: num(l.unit_price), discount_pct: num(l.discount_pct), line_total: num(l.line_total), vat_rate: l.vat_rate ?? null,
                })),
                summary: {
                    goods_total: summary.goods_total, commercial_discount: summary.commercial_discount, taxable_base: summary.taxable_base,
                    vat_total: summary.vat_total, withholding: summary.withholding, financial_discount: summary.financial_discount, total: summary.total,
                    vat_breakdown: summary.vat_breakdown.filter((b) => b.rate).map((b) => ({ rate: b.rate, base: num(b.base), vat: num(b.vat) })),
                },
            });
            toast.success("Fatura validada e guardada.");
            navigate("/restauracao/faturas");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar a fatura.");
        } finally {
            setSaving(false);
        }
    };

    // Cabeçalho comum (página de detalhe: o título é o registo, o breadcrumb é curto).
    const crumbs: Crumb[] = [{ label: "Restauração" }, { label: "Faturas", to: "/restauracao/faturas" }];
    const pageTitle = inv?.number ? `Fatura ${inv.number}` : "Validar fatura";

    if (loading) {
        return <div className="page-content"><Container fluid><PageHeader title="Validar fatura" breadcrumbs={crumbs} crumbLabel="Validar" /><div className="text-center py-5"><Spinner /> A carregar…</div></Container></div>;
    }

    if (inv?.status === "processing") {
        return (
            <div className="page-content"><ToastContainer /><Container fluid>
                <PageHeader title={pageTitle} breadcrumbs={crumbs} crumbLabel="Validar" />
                <div className="text-center py-5">
                    <Spinner className="mb-3" style={{ width: 48, height: 48 }} />
                    <h5>A ler a fatura com IA…</h5>
                    <p className="text-muted">Isto demora alguns segundos. A página atualiza sozinha.</p>
                </div>
            </Container></div>
        );
    }

    const reprocessModal = (
        <Modal isOpen={confirmReprocess} toggle={() => !reprocessing && setConfirmReprocess(false)} centered>
            <ModalHeader toggle={() => !reprocessing && setConfirmReprocess(false)}>Reprocessar a fatura?</ModalHeader>
            <ModalBody>
                A fatura volta a ser lida (QR e linhas). O que está neste ecrã e ainda não foi guardado é substituído pela nova leitura.
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={() => setConfirmReprocess(false)} disabled={reprocessing}>Cancelar</Button>
                <Button color="primary" onClick={reprocess} disabled={reprocessing}>
                    {reprocessing ? <><Spinner size="sm" className="me-1" /> A pedir…</> : <><i className="ri-refresh-line me-1" />Reprocessar</>}
                </Button>
            </ModalFooter>
        </Modal>
    );

    if (inv?.status === "erro") {
        return (
            <div className="page-content"><ToastContainer /><Container fluid>
                <PageHeader title={pageTitle} breadcrumbs={crumbs} crumbLabel="Validar" />
                <Alert color="danger" className="mt-3">
                    <h5 className="alert-heading">Não foi possível ler a fatura</h5>
                    <p className="mb-2">{inv.error_message || "Erro ao processar. Tente enviar uma imagem mais nítida."}</p>
                    <div className="d-flex gap-2">
                        <Button size="sm" color="primary" onClick={() => setConfirmReprocess(true)}><i className="ri-refresh-line me-1" />Reprocessar</Button>
                        <Button size="sm" color="outline-primary" onClick={() => navigate("/restauracao/faturas")}>Voltar às faturas</Button>
                    </div>
                </Alert>
                {reprocessModal}
            </Container></div>
        );
    }

    if (inv?.status === "nao_desta_empresa") {
        return (
            <div className="page-content"><ToastContainer /><Container fluid>
                <PageHeader title={pageTitle} breadcrumbs={crumbs} crumbLabel="Validar" />
                <Alert color="secondary" className="mt-3">
                    <h5 className="alert-heading"><i className="ri-qr-code-line me-1" />Esta fatura não é desta empresa</h5>
                    <p className="mb-2">{inv.error_message}</p>
                    <p className="mb-2 fs-13 text-muted">Lido do QR (sem IA): emitente {inv.supplier_nif ?? "—"} · adquirente {inv.buyer_nif ?? "—"} · {inv.doc_type ?? ""} {inv.number ?? ""} · {inv.issue_date ?? ""} · ATCUD {inv.atcud ?? "—"}</p>
                    <Button size="sm" color="primary" onClick={() => navigate("/restauracao/faturas")}>Voltar às faturas</Button>
                </Alert>
            </Container></div>
        );
    }

    // Campos do QR: só leitura, com tooltip "lido do QR".
    const qrLocked = hasQr ? { readOnly: true, title: "lido do QR", className: "form-control form-control-sm bg-light" } : {};
    const qrMark = hasQr ? <i className="ri-qr-code-line ms-1 text-success" title="lido do QR" aria-label="lido do QR" /> : null;

    const numInput = (value: number | null, onChange: (v: number | null) => void, extra: any = {}) => (
        <input type="number" step="0.01" className="form-control form-control-sm text-end"
            value={value ?? ""} onChange={(e) => onChange(num(e.target.value))} {...extra} />
    );

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title={pageTitle}
                    crumbLabel="Validar"
                    breadcrumbs={crumbs}
                    description={<>
                        <span className="badge bg-info-subtle text-info me-2"><i className="ri-robot-2-line me-1" />Lido por IA: verifique os dados</span>
                        {inv?.qr_ok !== null && inv?.qr_ok !== undefined && (
                            inv.qr_ok
                                ? <span className="badge bg-success-subtle text-success me-2" title="Cabeçalho e totais lidos do QR da AT, sem IA"><i className="ri-qr-code-line me-1" />QR ✓</span>
                                : <span className="badge bg-warning-subtle text-warning me-2" title="Sem QR legível: o cabeçalho foi lido pela IA"><i className="ri-qr-code-line me-1" />QR ✗</span>
                        )}
                        {inv?.lines_source && (
                            <span className="badge bg-light text-body me-2">
                                <i className={`${inv.lines_source === "texto" ? "ri-file-text-line" : "ri-image-line"} me-1`} />
                                Linhas {inv.lines_source === "texto" ? "do texto do PDF" : "da imagem"}
                            </span>
                        )}
                        {inv?.confidence ? `${inv.confidence}% confiança` : ""}
                        {inv?.model ? ` · ${inv.model}` : ""}
                        {inv?.attempts && inv.attempts > 1 ? ` · ${inv.attempts} tentativas` : ""}
                    </>}
                    actions={<>
                        <Button color="outline-primary" onClick={() => navigate("/restauracao/faturas")} disabled={saving}>Voltar</Button>
                        {inv?.status !== "validada" && (
                            <Button color="outline-secondary" onClick={() => setConfirmReprocess(true)} disabled={saving}><i className="ri-refresh-line me-1" />Reprocessar</Button>
                        )}
                        <Button color="primary" onClick={save} disabled={saving}>
                            {saving ? <><Spinner size="sm" className="me-1" /> A guardar…</> : <><i className="ri-check-double-line me-1" /> Validar e guardar</>}
                        </Button>
                    </>}
                />

                {inv?.confidence !== undefined && inv.confidence < 60 && (
                    <Alert color="warning" className="py-2"><i className="ri-alert-line me-1" /> Confiança baixa ({inv.confidence}%): confira tudo com atenção contra a imagem.</Alert>
                )}
                {warnings.length > 0 && (
                    <Alert color="warning" className="py-2">
                        <strong><i className="ri-error-warning-line me-1" />Os totais não fecham:</strong>
                        <ul className="mb-0 mt-1">{warnings.map((w, i) => <li key={i} className="fs-13">{w}</li>)}</ul>
                    </Alert>
                )}

                {inv?.check_status === "confere" && (
                    <Alert color="success" className="py-2"><i className="ri-checkbox-circle-line me-1" /><strong>Confere com o QR:</strong> a soma das linhas por taxa de IVA bate com as bases do QR da AT.</Alert>
                )}
                {inv?.check_status === "nao_confere" && (
                    <Alert color="warning" className="py-2">
                        <strong><i className="ri-error-warning-line me-1" />As linhas não conferem com o QR</strong>
                        <span className="fs-13"> — verifique as linhas contra a imagem (falta, sobra ou valor mal lido).</span>
                        <table className="table table-sm table-borderless mb-0 mt-1 fs-13" style={{ maxWidth: 520 }}>
                            <thead><tr className="text-muted"><th>Taxa</th><th className="text-end">Base no QR</th><th className="text-end">Soma das linhas</th><th className="text-end">Diferença</th></tr></thead>
                            <tbody>
                                {inv.check_diff.map((r, i) => (
                                    <tr key={i} className={r.ok ? "text-muted" : "fw-semibold"}>
                                        <td>{r.rate === null ? "Sem taxa" : r.rate === 0 ? "Isento" : `${r.rate}%`}</td>
                                        <td className="text-end">{eur(r.qr)}</td>
                                        <td className="text-end">{eur(r.lines)}</td>
                                        <td className="text-end">{r.ok ? "✓" : `${r.diff > 0 ? "+" : ""}${eur(r.diff)}`}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Alert>
                )}
                {inv?.check_status === "sem_qr" && (
                    <Alert color="info" className="py-2"><i className="ri-qr-code-line me-1" />Sem QR legível: o cabeçalho foi lido pela IA e não há conferência automática. Confira com a imagem.</Alert>
                )}

                <Row className="g-3">
                    {/* Coluna dados (editável) */}
                    <Col lg={7}>
                        <Card>
                            <div className="card-header"><h5 className="card-title mb-0">Fornecedor & fatura</h5></div>
                            <CardBody>
                                <Row className="g-2">
                                    <Col md={12}>
                                        <Label className="fs-12 text-muted mb-1">Fornecedor (ligar ao sincronizado)</Label>
                                        <Select styles={reactSelectTheme} menuPortalTarget={document.body} isClearable
                                            options={supplierOptions}
                                            value={supplierOptions.find((o) => o.value === supplierId) ?? null}
                                            onChange={(o: any) => setSupplierId(o?.value ?? null)}
                                            placeholder="Escolher fornecedor…" />
                                    </Col>
                                    <Col md={6}><Label className="fs-12 text-muted mb-1">Nome (lido)</Label><input className="form-control form-control-sm" value={supplierName} onChange={(e) => setSupplierName(e.target.value)} /></Col>
                                    <Col md={6}><Label className="fs-12 text-muted mb-1">NIF{qrMark}</Label><input className="form-control form-control-sm" value={supplierNif} onChange={(e) => setSupplierNif(e.target.value)} {...qrLocked} /></Col>
                                    <Col md={6}><Label className="fs-12 text-muted mb-1">Nº fatura{qrMark}</Label><input className="form-control form-control-sm" value={number} onChange={(e) => setNumber(e.target.value)} {...qrLocked} /></Col>
                                    <Col md={6}><Label className="fs-12 text-muted mb-1">Data emissão{qrMark}</Label><input type="date" className="form-control form-control-sm" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} {...qrLocked} /></Col>
                                    {(inv?.buyer_nif || inv?.atcud) && <>
                                        <Col md={6}><Label className="fs-12 text-muted mb-1">NIF adquirente{qrMark}</Label><input className="form-control form-control-sm bg-light" value={inv?.buyer_nif ?? ""} readOnly title="lido do QR" /></Col>
                                        <Col md={6}><Label className="fs-12 text-muted mb-1">ATCUD{qrMark}</Label><input className="form-control form-control-sm bg-light" value={inv?.atcud ?? ""} readOnly title="lido do QR" /></Col>
                                    </>}
                                </Row>
                            </CardBody>
                        </Card>

                        <Card>
                            <div className="card-header d-flex justify-content-between align-items-center">
                                <h5 className="card-title mb-0">Linhas</h5>
                                <Button size="sm" color="outline-primary" onClick={() => setLines((p) => [...p, emptyLine()])}><i className="ri-add-line me-1" />Adicionar linha</Button>
                            </div>
                            <div className="table-responsive">
                                <table className="table table-bordered align-middle mb-0" style={{ minWidth: 940 }}>
                                    <thead className="text-muted table-light">
                                        <tr>
                                            <th style={{ minWidth: 90 }}>Cód. fornecedor</th><th style={{ minWidth: 180 }}>Item</th><th>Qtd</th><th>Un.</th><th>Preço un.</th><th>Desc.%</th><th>Total</th><th>IVA</th><th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {lines.length === 0 ? (
                                            <tr><td colSpan={9} className="text-center text-muted py-3">Sem linhas. Use “Adicionar linha”.</td></tr>
                                        ) : lines.map((l, i) => (
                                            <tr key={i}>
                                                <td style={{ width: 110 }}><input className="form-control form-control-sm" value={l.supplier_code ?? ""} onChange={(e) => setLine(i, { supplier_code: e.target.value })} aria-label={`Código do fornecedor, linha ${i + 1}`} /></td>
                                                <td><input className="form-control form-control-sm" value={l.item ?? ""} onChange={(e) => setLine(i, { item: e.target.value })} /></td>
                                                <td style={{ width: 80 }}>{numInput(l.quantity, (v) => setLine(i, { quantity: v }))}</td>
                                                <td style={{ width: 70 }}><input className="form-control form-control-sm" value={l.unit ?? ""} onChange={(e) => setLine(i, { unit: e.target.value })} /></td>
                                                <td style={{ width: 100 }}>{numInput(l.unit_price, (v) => setLine(i, { unit_price: v }))}</td>
                                                <td style={{ width: 80 }}>{numInput(l.discount_pct, (v) => setLine(i, { discount_pct: v }))}</td>
                                                <td style={{ width: 100 }}>{numInput(l.line_total, (v) => setLine(i, { line_total: v }))}</td>
                                                <td style={{ width: 120, minWidth: 120 }}>
                                                    <Select styles={reactSelectTheme} menuPortalTarget={document.body} isClearable
                                                        options={VAT_OPTS} value={VAT_OPTS.find((o) => o.value === l.vat_rate) ?? null}
                                                        onChange={(o: any) => setLine(i, { vat_rate: o?.value ?? null })} placeholder="Taxa" aria-label="Taxa de IVA" />
                                                </td>
                                                <td style={{ width: 40 }}><button type="button" className="btn btn-sm btn-outline-danger" aria-label={`Remover linha ${i + 1}`} onClick={() => setLines((p) => p.filter((_, idx) => idx !== i))}><i className="ri-delete-bin-line" /></button></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Card>

                        <Card>
                            <div className="card-header"><h5 className="card-title mb-0">Sumário</h5></div>
                            <CardBody>
                                {/* ⚠️ Soma das linhas calculada pela Xplendor (mais fiável que o sumário da IA). */}
                                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 p-2 mb-3"
                                    style={{ background: "var(--vz-primary-bg-subtle)", border: "1px solid var(--vz-border-color)", borderRadius: 8 }}>
                                    <div>
                                        <span className="text-muted fs-12 text-uppercase fw-semibold">Soma das linhas (Xplendor)</span>
                                        <div className="fw-semibold fs-18">{eur(linesSum)}</div>
                                        {!approx(linesSum, summary.goods_total) && (
                                            <div className="text-muted fs-12">Sumário da IA (mercadorias): {eur(summary.goods_total)}</div>
                                        )}
                                    </div>
                                    <Button size="sm" color="outline-primary"
                                        onClick={() => setSum({ goods_total: round2(linesSum), taxable_base: summary.taxable_base ? summary.taxable_base : round2(linesSum) })}>
                                        <i className="ri-download-line me-1" />Usar nas mercadorias
                                    </Button>
                                </div>
                                <Row className="g-2">
                                    {([
                                        ["Total mercadorias", "goods_total"], ["Desconto comercial", "commercial_discount"],
                                        ["Base tributável", "taxable_base"], ["Valor IVA total", "vat_total"],
                                        ["Retenção na fonte", "withholding"], ["Desconto financeiro", "financial_discount"],
                                    ] as [string, keyof OcrInvoiceSummary][]).map(([label, key]) => (
                                        <Col md={4} key={key}>
                                            <Label className="fs-12 text-muted mb-1">{label}</Label>
                                            {numInput(summary[key] as number, (v) => setSum({ [key]: v ?? 0 } as any))}
                                        </Col>
                                    ))}
                                    <Col md={4}>
                                        <Label className="fs-12 text-muted mb-1 fw-semibold">Total</Label>
                                        {numInput(summary.total, (v) => setSum({ total: v ?? 0 }), { className: "form-control form-control-sm text-end fw-semibold" })}
                                    </Col>
                                </Row>

                                <div className="d-flex justify-content-between align-items-center mt-3 mb-1">
                                    <span className="fs-12 text-muted text-uppercase fw-semibold">IVA por taxa</span>
                                    <Button size="sm" color="outline-primary" aria-label="Adicionar taxa de IVA" title="Adicionar taxa de IVA" onClick={() => setSum({ vat_breakdown: [...summary.vat_breakdown, { rate: 23, base: null, vat: null }] })}><i className="ri-add-line" /></Button>
                                </div>
                                {summary.vat_breakdown.map((b, i) => (
                                    <Row className="g-2 mb-1 align-items-center" key={i}>
                                        <Col xs={4}><Select styles={reactSelectTheme} menuPortalTarget={document.body} options={VAT_OPTS} value={VAT_OPTS.find((o) => o.value === b.rate) ?? null} onChange={(o: any) => setVat(i, { rate: o?.value ?? null })} placeholder="Taxa" aria-label="Taxa de IVA" /></Col>
                                        <Col xs={3}>{numInput(b.base, (v) => setVat(i, { base: v }))}</Col>
                                        <Col xs={3}>{numInput(b.vat, (v) => setVat(i, { vat: v }))}</Col>
                                        <Col xs={2}><button type="button" className="btn btn-sm btn-outline-danger" aria-label={`Remover taxa ${i + 1}`} onClick={() => setSum({ vat_breakdown: summary.vat_breakdown.filter((_, idx) => idx !== i) })}><i className="ri-delete-bin-line" /></button></Col>
                                    </Row>
                                ))}
                            </CardBody>
                        </Card>

                        {pingwin && (
                            <PingwinLinkCard companyId={companyId} invoiceId={invoiceId} block={pingwin} onBlock={setPingwin}
                                onCreateSupplier={(nif, name) => navigate(`/restauracao/fornecedores?${new URLSearchParams({ novo: "1", nif: nif ?? "", nome: name ?? "" }).toString()}`)}
                                onOpenInvoice={(id) => navigate(`/restauracao/faturas/${id}`)} />
                        )}
                    </Col>

                    {/* Coluna imagem */}
                    <Col lg={5}>
                        <Card className="sticky-top" style={{ top: 80 }}>
                            <div className="card-header"><h5 className="card-title mb-0">Imagem da fatura</h5></div>
                            <CardBody className="text-center">
                                {!imageUrl ? (
                                    <div className="text-muted py-5"><Spinner size="sm" className="me-1" /> A carregar imagem…</div>
                                ) : imageIsPdf ? (
                                    <iframe title="fatura" src={imageUrl} style={{ width: "100%", height: "70vh", border: "1px solid var(--vz-border-color)", borderRadius: 8 }} />
                                ) : (
                                    <img src={imageUrl} alt="Fatura" style={{ maxWidth: "100%", borderRadius: 8, border: "1px solid var(--vz-border-color)" }} />
                                )}
                            </CardBody>
                        </Card>
                    </Col>
                </Row>
                {reprocessModal}
            </Container>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// F3 — bloco "No PingWin"
// ─────────────────────────────────────────────────────────────────────────────

const METHOD_LABEL: Record<OcrLinkMethod, string> = {
    numero: "Pelo nº da fatura",
    total_data: "Pelo total e pela data",
    guias: "Fatura de guias",
    manual: "Escolhido à mão",
};
const fmtDay = (d?: string | null) => (d ? d.split("-").reverse().join("/") : "—");
const signedEur = (n: number) => `${n > 0 ? "+" : ""}${eur(n)}`;

type PingwinLinkCardProps = {
    companyId: number;
    invoiceId: number;
    block: OcrPingwinBlock;
    onBlock: (b: OcrPingwinBlock) => void;
    onCreateSupplier: (nif: string | null, name: string | null) => void;
    onOpenInvoice: (id: number) => void;
};

/** Tabela de documentos do PingWin, com escolha única (radio) ou múltipla (checkbox). */
function PingwinDocsTable({ docs, select, selected, onToggle, caption }: {
    docs: OcrPingwinDoc[];
    select?: "radio" | "checkbox";
    selected?: string[];
    onToggle?: (id: string) => void;
    caption: string;
}) {
    return (
        <div className="table-responsive">
            <table className="table table-sm align-middle mb-0 fs-13">
                <caption className="visually-hidden">{caption}</caption>
                <thead className="text-muted table-light">
                    <tr>
                        {select && <th style={{ width: 32 }}><span className="visually-hidden">Escolher</span></th>}
                        <th>Documento</th><th>Lançado em</th><th>Nº doc. fornecedor</th><th className="text-end">Total</th><th className="text-center">Liquidado</th><th>Loja</th>
                    </tr>
                </thead>
                <tbody>
                    {docs.map((d) => {
                        const taken = d.linked_to_invoice !== null;
                        const checked = !!selected?.includes(d.docheader_id);
                        return (
                            <tr key={d.docheader_id} className={checked ? "table-primary" : undefined}>
                                {select && (
                                    <td>
                                        <input type={select} className="form-check-input" checked={checked} disabled={taken}
                                            name={select === "radio" ? `pw-${caption}` : undefined}
                                            onChange={() => onToggle?.(d.docheader_id)} aria-label={`Escolher ${d.document ?? d.docheader_id}`} />
                                    </td>
                                )}
                                <td className="fw-medium text-nowrap">
                                    {d.document ?? d.docheader_id}
                                    {taken && <span className="badge bg-secondary-subtle text-secondary ms-1" title="Já está ligado a outra fatura carregada">fatura #{d.linked_to_invoice}</span>}
                                </td>
                                <td className="text-nowrap">{fmtDay(d.doc_date)}</td>
                                <td>{d.docreference_number || <span className="text-muted">—</span>}</td>
                                <td className="text-end text-nowrap">{eur(d.total)}</td>
                                <td className="text-center">{d.paid ? <span className="badge bg-success-subtle text-success">Sim</span> : <span className="text-muted">Não</span>}</td>
                                <td>{d.store_name ?? "—"}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function PingwinLinkCard({ companyId, invoiceId, block, onBlock, onCreateSupplier, onOpenInvoice }: PingwinLinkCardProps) {
    const [busy, setBusy] = useState<string | null>(null);
    const [choosing, setChoosing] = useState(false);
    const [choice, setChoice] = useState<string | null>(null);
    const [guidesOpen, setGuidesOpen] = useState(false);
    const [guideSel, setGuideSel] = useState<string[]>([]);

    const st = block.status ? OCR_LINK_STATUS[block.status] : null;
    const linked = block.linked;
    const unconfirmed = linked.some((l) => !l.confirmed);
    const guideMode = block.candidates_mode === "guias";
    const total = block.invoice_total ?? 0;

    // Modo guias: por omissão escolhe todos os candidatos livres.
    useEffect(() => {
        setGuideSel(guideMode ? block.candidates.filter((d) => d.linked_to_invoice === null).map((d) => d.docheader_id) : []);
        setGuidesOpen(block.status === "possivel" && guideMode);
        setChoosing(false);
        setChoice(null);
    }, [block.status, block.candidates_mode, block.candidates, guideMode]);

    const run = async (key: string, fn: () => Promise<any>, ok: string) => {
        setBusy(key);
        try {
            const res: any = await fn();
            if (res?.data?.pingwin) onBlock(res.data.pingwin);
            toast.success(ok);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível concluir.");
        } finally {
            setBusy(null);
        }
    };
    const search = () => run("search", () => searchOcrPingwinLink(companyId, invoiceId), "Ligação ao PingWin verificada.");
    const confirm = (ids?: string[], method?: string) =>
        run("confirm", () => confirmOcrPingwinLink(companyId, invoiceId, ids ? { docheader_ids: ids, method } : {}), "Ligação ao PingWin guardada.");
    const unlink = () => run("unlink", () => unlinkOcrPingwinLink(companyId, invoiceId), "Documento desligado.");

    const guideDocs = block.candidates;
    const guideSum = guideDocs.filter((d) => guideSel.includes(d.docheader_id)).reduce((a, d) => a + d.total, 0);
    const toggleGuide = (id: string) => setGuideSel((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));

    const statusLine = (
        <span>
            {st && <span className={`badge ${st.cls} me-2`} title={st.title}>{st.label}</span>}
            {block.checked_at && <span className="text-muted">Verificado {new Date(block.checked_at).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" })}</span>}
        </span>
    );

    return (
        <PageCard
            title="No PingWin"
            info="A fatura ligada ao documento lançado no PingWin. Só lê os dados do PingWin já sincronizados: nada é gravado no PingWin."
            status={statusLine}
            flush={false}
            data-testid="pingwin-link-card"
            actions={<Button size="sm" color="outline-primary" onClick={search} disabled={!!busy || block.search_pending}>
                {busy === "search" || block.search_pending ? <><Spinner size="sm" className="me-1" />A procurar…</> : <><i className="ri-search-line me-1" />Procurar no PingWin</>}
            </Button>}
        >
            {block.note && <Alert color="warning" className="py-2 fs-13"><i className="ri-error-warning-line me-1" />{block.note}</Alert>}

            {block.search_pending && (
                <div className="text-muted fs-13 mb-2"><Spinner size="sm" className="me-1" />A procurar o fornecedor (NIF {block.supplier.nif}) no PingWin…</div>
            )}

            {block.status === "duplicada" && block.duplicate_of && (
                <p className="mb-0 fs-13">
                    Esta fatura já foi carregada antes ({block.duplicate_of.number ?? `fatura #${block.duplicate_of.id}`}).{" "}
                    <Button size="sm" color="link" className="p-0 align-baseline" onClick={() => onOpenInvoice(block.duplicate_of!.id)}>Abrir a original</Button>
                </p>
            )}

            {block.status === "fornecedor_em_falta" && !block.search_pending && (
                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span className="fs-13">
                        {block.supplier.nif
                            ? <>O fornecedor com NIF <strong>{block.supplier.nif}</strong> não existe no PingWin.</>
                            : block.supplier.own_nif
                                ? <>O NIF lido como fornecedor é o da própria empresa: corrija o NIF do fornecedor e valide, ou reprocesse a fatura.</>
                                : <>A fatura não tem o NIF do fornecedor.</>}
                    </span>
                    {block.supplier.nif && (
                        <Button size="sm" color="primary" onClick={() => onCreateSupplier(block.supplier.prefill.nif, block.supplier.prefill.name)}>
                            <i className="ri-user-add-line me-1" />Criar fornecedor
                        </Button>
                    )}
                </div>
            )}

            {/* Documento(s) ligado(s) */}
            {linked.length > 0 && (
                <>
                    <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <span className="fs-13">
                            {METHOD_LABEL[linked[0].method]}
                            {unconfirmed && <span className="badge bg-warning-subtle text-warning ms-2" title="Ligado automaticamente: confirme que é este o documento">Por confirmar</span>}
                            {!unconfirmed && <span className="badge bg-success-subtle text-success ms-2">Confirmado</span>}
                        </span>
                        <div className="d-flex gap-2">
                            {unconfirmed && <Button size="sm" color="primary" onClick={() => confirm()} disabled={!!busy}><i className="ri-check-line me-1" />Confirmar</Button>}
                            <Button size="sm" color="outline-secondary" onClick={() => setChoosing((v) => !v)} disabled={!!busy}>Escolher outro</Button>
                            <Button size="sm" color="outline-danger" onClick={unlink} disabled={!!busy}>Desligar</Button>
                        </div>
                    </div>
                    <PingwinDocsTable docs={linked} caption="Documentos ligados" />
                    {(linked.length > 1 || (block.diff !== null && block.diff !== 0)) && (
                        <div className="fs-13 mt-2">
                            {linked.length > 1 && <>Soma dos documentos: <strong>{eur(linked.reduce((a, d) => a + d.total, 0))}</strong> · </>}
                            Total da fatura: {eur(total)}
                            {block.diff !== null && block.diff !== 0 && <> · <span className="text-warning fw-semibold">Diferença {signedEur(block.diff)}</span></>}
                        </div>
                    )}
                    {block.compare && <PingwinCompare c={block.compare} />}
                </>
            )}

            {/* Possível: vários documentos com o mesmo total e data */}
            {block.status === "possivel" && block.candidates_mode === "total_data" && (
                <>
                    <p className="fs-13 mb-2">Há {block.candidates.length} documentos do fornecedor com o mesmo total e data próxima. Escolha o que corresponde a esta fatura:</p>
                    <PingwinDocsTable docs={block.candidates} select="radio" selected={choice ? [choice] : []} onToggle={setChoice} caption="Candidatos" />
                    <div className="text-end mt-2">
                        <Button size="sm" color="primary" disabled={!choice || !!busy} onClick={() => choice && confirm([choice], "total_data")}>Ligar a este documento</Button>
                    </div>
                </>
            )}

            {/* Sem ligação: escolher um documento à mão, ou várias guias */}
            {linked.length === 0 && ["nao_lancada", "possivel"].includes(block.status ?? "") && !guidesOpen && block.candidates_mode !== "total_data" && (
                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span className="fs-13">{block.status === "nao_lancada" ? "Não foi encontrada no PingWin: falta lançar, ou escolha o documento à mão." : ""}</span>
                    <div className="d-flex gap-2">
                        {block.choices.length > 0 && <Button size="sm" color="outline-secondary" onClick={() => setChoosing((v) => !v)}>Escolher documento</Button>}
                        {guideMode && guideDocs.length > 0 && <Button size="sm" color="outline-secondary" onClick={() => setGuidesOpen(true)}>Fatura de guias…</Button>}
                    </div>
                </div>
            )}

            {/* Modo guias */}
            {linked.length === 0 && guideMode && guidesOpen && guideDocs.length > 0 && (
                <>
                    <p className="fs-13 mb-2">
                        {block.guides.length > 0
                            ? <>A fatura refere <strong>{block.guides.length} guias</strong> ({fmtDay(block.period?.from)} a {fmtDay(block.period?.to)}). </>
                            : <>Documentos do fornecedor nos 35 dias até à data da fatura ({fmtDay(block.period?.from)} a {fmtDay(block.period?.to)}). </>}
                        Escolha os documentos que esta fatura junta:
                    </p>
                    <PingwinDocsTable docs={guideDocs} select="checkbox" selected={guideSel} onToggle={toggleGuide} caption="Guias" />
                    <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2">
                        <span className="fs-13" data-testid="guides-sum">
                            {guideSel.length} escolhidos · soma <strong>{eur(guideSel.length ? guideSum : 0)}</strong> · fatura {eur(total)} ·{" "}
                            <span className={Math.abs(guideSum - total) < 0.005 ? "text-success fw-semibold" : "text-warning fw-semibold"}>
                                diferença {signedEur(Math.round((guideSum - total) * 100) / 100)}
                            </span>
                        </span>
                        <div className="d-flex gap-2">
                            {block.status !== "possivel" && <Button size="sm" color="light" onClick={() => setGuidesOpen(false)}>Cancelar</Button>}
                            <Button size="sm" color="primary" disabled={guideSel.length === 0 || !!busy} onClick={() => confirm(guideSel, "guias")}>
                                <i className="ri-check-double-line me-1" />Confirmar guias
                            </Button>
                        </div>
                    </div>
                </>
            )}

            {/* Escolher outro / escolher à mão */}
            {choosing && (
                <div className="mt-3">
                    {block.choices.length === 0 ? (
                        <p className="text-muted fs-13 mb-0">Sem documentos deste fornecedor perto da data da fatura.</p>
                    ) : (
                        <>
                            <p className="fs-13 mb-2">Documentos do fornecedor perto da data da fatura:</p>
                            <PingwinDocsTable docs={block.choices} select="radio" selected={choice ? [choice] : []} onToggle={setChoice} caption="Escolher documento" />
                            <div className="text-end mt-2 d-flex justify-content-end gap-2">
                                <Button size="sm" color="light" onClick={() => setChoosing(false)}>Cancelar</Button>
                                <Button size="sm" color="primary" disabled={!choice || !!busy} onClick={() => choice && confirm([choice], "manual")}>Ligar a este documento</Button>
                            </div>
                        </>
                    )}
                </div>
            )}
        </PageCard>
    );
}

/** Linhas OCR × PingWin (só informativo, com 1 documento ligado). */
function PingwinCompare({ c }: { c: NonNullable<OcrPingwinBlock["compare"]> }) {
    if (!c.pw_synced) {
        return <p className="text-muted fs-13 mt-3 mb-0">As linhas deste documento do PingWin ainda não foram sincronizadas.</p>;
    }
    const same = c.unmatched_ocr.length === 0 && c.unmatched_pw.length === 0;
    return (
        <div className="mt-3 p-2" style={{ border: "1px solid var(--vz-border-color)", borderRadius: 8 }} data-testid="pingwin-compare">
            <div className="fs-12 text-muted text-uppercase fw-semibold mb-1">Linhas: fatura × PingWin (informativo)</div>
            <div className="fs-13">
                Fatura: {c.ocr_count} linhas, {eur(c.ocr_sum)} · PingWin: {c.pw_count} linhas, {eur(c.pw_sum)}
                {same && <span className="badge bg-success-subtle text-success ms-2">Todas as linhas batem</span>}
            </div>
            {!same && (
                <Row className="g-2 mt-1 fs-13">
                    <Col md={6}>
                        <div className="fw-semibold mb-1">Só na fatura ({c.unmatched_ocr.length})</div>
                        {c.unmatched_ocr.length === 0 ? <span className="text-muted">—</span> : (
                            <ul className="mb-0 ps-3">{c.unmatched_ocr.map((l, i) => <li key={i}>{l.code ? `${l.code} · ` : ""}{l.description ?? "—"} — {eur(l.total)}</li>)}</ul>
                        )}
                    </Col>
                    <Col md={6}>
                        <div className="fw-semibold mb-1">Só no PingWin ({c.unmatched_pw.length})</div>
                        {c.unmatched_pw.length === 0 ? <span className="text-muted">—</span> : (
                            <ul className="mb-0 ps-3">{c.unmatched_pw.map((l, i) => <li key={i}>{l.code ? `${l.code} · ` : ""}{l.description ?? "—"} — {eur(l.total)}</li>)}</ul>
                        )}
                    </Col>
                </Row>
            )}
        </div>
    );
}
