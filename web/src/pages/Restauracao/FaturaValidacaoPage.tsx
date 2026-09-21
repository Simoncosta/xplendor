import { useCallback, useEffect, useMemo, useState } from "react";
import { Card, CardBody, Container, Row, Col, Spinner, Label, Alert } from "reactstrap";
import { useNavigate, useParams } from "react-router-dom";
import { toast, ToastContainer } from "react-toastify";
import Select from "react-select";
import { reactSelectTheme } from "../../helpers/reactSelectStyles";
import { getOcrInvoice, updateOcrInvoice, getOcrInvoiceImageBlob } from "helpers/laravel_helper";
import { OcrInvoiceDetail, OcrInvoiceLine, OcrInvoiceSummary, OcrVatBreakdownRow, OcrSupplierOption } from "common/models/ocr.model";

/**
 * XPLENDOR — Restauração › Faturas › Validação (OCR Fase A, o CORE). Mostra o que
 * a IA leu (linhas + sumário) EDITÁVEL, com a imagem ao lado para conferir, e
 * avisa quando os totais não fecham (não bloqueia — o utilizador decide). Ao
 * guardar, valida NA XPLENDOR. ⚠️ NÃO escreve no PingWin.
 */

const VAT_OPTS = [6, 13, 23].map((v) => ({ value: v, label: `${v}%` }));
const num = (v: any): number | null => (v === "" || v === null || v === undefined || isNaN(Number(v)) ? null : Number(v));
const eur = (n?: number | null) => (n ?? 0).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });
const approx = (a: number, b: number) => Math.abs(a - b) <= Math.max(0.05, Math.abs(b) * 0.01);

const emptyLine = (): OcrInvoiceLine => ({ item: "", quantity: null, unit: "", unit_price: null, discount_pct: null, line_total: null, vat_rate: null });
const emptySummary = (): OcrInvoiceSummary => ({ goods_total: 0, commercial_discount: 0, taxable_base: 0, vat_total: 0, withholding: 0, financial_discount: 0, total: 0, vat_breakdown: [] });

export default function FaturaValidacaoPage() {
    document.title = "Validar fatura | Restauração | Xplendor";
    const navigate = useNavigate();
    const { id } = useParams();
    const invoiceId = Number(id);

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

    const [inv, setInv] = useState<OcrInvoiceDetail | null>(null);
    const [suppliers, setSuppliers] = useState<OcrSupplierOption[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [imageUrl, setImageUrl] = useState<string | null>(null);
    const [imageIsPdf, setImageIsPdf] = useState(false);

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

    // Avisos de coerência (não bloqueiam).
    const warnings = useMemo(() => {
        const w: string[] = [];
        if (lines.length > 0 && !approx(linesSum, summary.taxable_base))
            w.push(`As linhas somam ${eur(linesSum)}, mas o sumário da IA diz base tributável ${eur(summary.taxable_base)} — confere.`);
        if (!approx(summary.goods_total - summary.commercial_discount, summary.taxable_base))
            w.push(`Mercadorias − desconto comercial (${eur(summary.goods_total - summary.commercial_discount)}) ≠ base tributável (${eur(summary.taxable_base)}).`);
        const chain = summary.taxable_base + summary.vat_total - summary.withholding - summary.financial_discount;
        if (!approx(chain, summary.total))
            w.push(`Base + IVA − retenção − desc. financeiro (${eur(chain)}) ≠ total (${eur(summary.total)}).`);
        return w;
    }, [lines.length, linesSum, summary]);

    const save = async () => {
        setSaving(true);
        try {
            await updateOcrInvoice(companyId, invoiceId, {
                supplier_id: supplierId, supplier_name: supplierName || null, supplier_nif: supplierNif || null,
                number: number || null, issue_date: issueDate || null,
                lines: lines.map((l) => ({
                    item: l.item || null, quantity: num(l.quantity), unit: l.unit || null,
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

    if (loading) {
        return <div className="page-content"><Container fluid><div className="text-center py-5"><Spinner /> A carregar…</div></Container></div>;
    }

    if (inv?.status === "processing") {
        return (
            <div className="page-content"><ToastContainer /><Container fluid>
                <div className="text-center py-5">
                    <Spinner className="mb-3" style={{ width: 48, height: 48 }} />
                    <h5>A ler a fatura com IA…</h5>
                    <p className="text-muted">Isto demora alguns segundos. A página atualiza sozinha.</p>
                </div>
            </Container></div>
        );
    }

    if (inv?.status === "erro") {
        return (
            <div className="page-content"><ToastContainer /><Container fluid>
                <Alert color="danger" className="mt-3">
                    <h5 className="alert-heading">Não foi possível ler a fatura</h5>
                    <p className="mb-2">{inv.error_message || "Erro ao processar. Tenta enviar uma imagem mais nítida."}</p>
                    <button className="btn btn-sm btn-primary" onClick={() => navigate("/restauracao/faturas")}>Voltar às faturas</button>
                </Alert>
            </Container></div>
        );
    }

    const numInput = (value: number | null, onChange: (v: number | null) => void, extra: any = {}) => (
        <input type="number" step="0.01" className="form-control form-control-sm text-end"
            value={value ?? ""} onChange={(e) => onChange(num(e.target.value))} {...extra} />
    );

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row>
                    <Col xs={12}>
                        <div className="page-title-box d-sm-flex align-items-center justify-content-between">
                            <div>
                                <h4 className="mb-sm-0">Validar fatura</h4>
                                <small className="text-muted">
                                    <span className="badge bg-info-subtle text-info me-2"><i className="ri-robot-2-line me-1" />Lido por IA — verifica os dados</span>
                                    {inv?.confidence ? `${inv.confidence}% confiança` : ""}
                                    {inv?.model ? ` · ${inv.model}` : ""}
                                </small>
                            </div>
                            <div className="d-flex gap-2">
                                <button className="btn btn-light" onClick={() => navigate("/restauracao/faturas")} disabled={saving}>Voltar</button>
                                <button className="btn btn-success" onClick={save} disabled={saving}>
                                    {saving ? <><Spinner size="sm" className="me-1" /> A guardar…</> : <><i className="ri-check-double-line me-1" /> Validar e guardar</>}
                                </button>
                            </div>
                        </div>
                    </Col>
                </Row>

                {inv?.confidence !== undefined && inv.confidence < 60 && (
                    <Alert color="warning" className="py-2"><i className="ri-alert-line me-1" /> Confiança baixa ({inv.confidence}%) — confere tudo com atenção contra a imagem.</Alert>
                )}
                {warnings.length > 0 && (
                    <Alert color="warning" className="py-2">
                        <strong><i className="ri-error-warning-line me-1" />Os totais não fecham:</strong>
                        <ul className="mb-0 mt-1">{warnings.map((w, i) => <li key={i} className="fs-13">{w}</li>)}</ul>
                    </Alert>
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
                                    <Col md={6}><Label className="fs-12 text-muted mb-1">NIF</Label><input className="form-control form-control-sm" value={supplierNif} onChange={(e) => setSupplierNif(e.target.value)} /></Col>
                                    <Col md={6}><Label className="fs-12 text-muted mb-1">Nº fatura</Label><input className="form-control form-control-sm" value={number} onChange={(e) => setNumber(e.target.value)} /></Col>
                                    <Col md={6}><Label className="fs-12 text-muted mb-1">Data emissão</Label><input type="date" className="form-control form-control-sm" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} /></Col>
                                </Row>
                            </CardBody>
                        </Card>

                        <Card>
                            <div className="card-header d-flex justify-content-between align-items-center">
                                <h5 className="card-title mb-0">Linhas</h5>
                                <button className="btn btn-sm btn-soft-primary" onClick={() => setLines((p) => [...p, emptyLine()])}><i className="ri-add-line me-1" />Adicionar linha</button>
                            </div>
                            <div className="table-responsive">
                                <table className="table table-bordered align-middle mb-0" style={{ minWidth: 720 }}>
                                    <thead className="text-muted table-light">
                                        <tr>
                                            <th style={{ minWidth: 180 }}>Item</th><th>Qtd</th><th>Un.</th><th>Preço un.</th><th>Desc.%</th><th>Total</th><th>IVA</th><th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {lines.length === 0 ? (
                                            <tr><td colSpan={8} className="text-center text-muted py-3">Sem linhas. Usa “Adicionar linha”.</td></tr>
                                        ) : lines.map((l, i) => (
                                            <tr key={i}>
                                                <td><input className="form-control form-control-sm" value={l.item ?? ""} onChange={(e) => setLine(i, { item: e.target.value })} /></td>
                                                <td style={{ width: 80 }}>{numInput(l.quantity, (v) => setLine(i, { quantity: v }))}</td>
                                                <td style={{ width: 70 }}><input className="form-control form-control-sm" value={l.unit ?? ""} onChange={(e) => setLine(i, { unit: e.target.value })} /></td>
                                                <td style={{ width: 100 }}>{numInput(l.unit_price, (v) => setLine(i, { unit_price: v }))}</td>
                                                <td style={{ width: 80 }}>{numInput(l.discount_pct, (v) => setLine(i, { discount_pct: v }))}</td>
                                                <td style={{ width: 100 }}>{numInput(l.line_total, (v) => setLine(i, { line_total: v }))}</td>
                                                <td style={{ width: 90 }}>
                                                    <Select styles={reactSelectTheme} menuPortalTarget={document.body} isClearable
                                                        options={VAT_OPTS} value={VAT_OPTS.find((o) => o.value === l.vat_rate) ?? null}
                                                        onChange={(o: any) => setLine(i, { vat_rate: o?.value ?? null })} placeholder="—" />
                                                </td>
                                                <td style={{ width: 40 }}><button className="btn btn-sm btn-soft-danger" onClick={() => setLines((p) => p.filter((_, idx) => idx !== i))}><i className="ri-delete-bin-line" /></button></td>
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
                                    <button className="btn btn-sm btn-soft-primary"
                                        onClick={() => setSum({ goods_total: round2(linesSum), taxable_base: summary.taxable_base ? summary.taxable_base : round2(linesSum) })}>
                                        <i className="ri-download-line me-1" />Usar nas mercadorias
                                    </button>
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
                                    <button className="btn btn-sm btn-soft-secondary" onClick={() => setSum({ vat_breakdown: [...summary.vat_breakdown, { rate: 23, base: null, vat: null }] })}><i className="ri-add-line" /></button>
                                </div>
                                {summary.vat_breakdown.map((b, i) => (
                                    <Row className="g-2 mb-1 align-items-center" key={i}>
                                        <Col xs={4}><Select styles={reactSelectTheme} menuPortalTarget={document.body} options={VAT_OPTS} value={VAT_OPTS.find((o) => o.value === b.rate) ?? null} onChange={(o: any) => setVat(i, { rate: o?.value ?? null })} placeholder="Taxa" /></Col>
                                        <Col xs={3}>{numInput(b.base, (v) => setVat(i, { base: v }))}</Col>
                                        <Col xs={3}>{numInput(b.vat, (v) => setVat(i, { vat: v }))}</Col>
                                        <Col xs={2}><button className="btn btn-sm btn-soft-danger" onClick={() => setSum({ vat_breakdown: summary.vat_breakdown.filter((_, idx) => idx !== i) })}><i className="ri-delete-bin-line" /></button></Col>
                                    </Row>
                                ))}
                            </CardBody>
                        </Card>
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
            </Container>
        </div>
    );
}
