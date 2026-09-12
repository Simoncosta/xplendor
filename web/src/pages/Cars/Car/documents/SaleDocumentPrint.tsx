// React
import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { Modal, ModalHeader, ModalBody, ModalFooter, Row, Col, Label, Input, Spinner } from "reactstrap";
// Helpers / molde
import { getSaleDocumentData } from "helpers/laravel_helper";
import { SaleDocumentData } from "types/api";
import { getSaleDocument } from "./registry";
import { buildInitialValues, type DocFieldDef } from "./types";

/**
 * DMS Fase 3 — motor PARTILHADO de impressão dos documentos de venda.
 * Reutiliza a técnica da ficha A4 (CarPrintSheet): HTML + @media print + @page
 * A4 + window.print() + MESMA aba (nova aba parte a sessão → login).
 *
 * Fluxo: escolher documento (na ficha) → esta página abre o MODAL de campos
 * (entidades pré-preenchidas, manuais vazios, todos editáveis) → "Ver documento"
 * → pré-visualização → Imprimir. Um só ficheiro serve TODOS os documentos do
 * registry — o 2.º documento não toca aqui.
 */
const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";

export default function SaleDocumentPrint() {
    const { companyId, id, docId } = useParams();
    const navigate = useNavigate();

    const [data, setData] = useState<SaleDocumentData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [values, setValues] = useState<Record<string, string>>({});
    const [modalOpen, setModalOpen] = useState(true);
    // Logo com fallback textual (mesma abordagem da ficha A4 CarPrintSheet):
    // em dev o ficheiro pode não existir → onError cai no nome da empresa.
    const [logoBroken, setLogoBroken] = useState(false);

    const def = useMemo(() => (docId ? getSaleDocument(docId) : null), [docId]);

    const goBack = () => {
        if (id) navigate(`/cars/${id}/ficha`);
        else window.history.back();
    };

    useEffect(() => {
        document.title = (def?.title ?? "Documento") + " | Xplendor";
        if (!companyId || !id) return;
        setLoading(true);
        setError(false);
        getSaleDocumentData(Number(companyId), Number(id))
            .then((res: any) => setData((res?.data as SaleDocumentData) ?? null))
            .catch(() => setError(true))
            .finally(() => setLoading(false));
    }, [companyId, id, def]);

    // Assim que os dados chegam, resolve os valores iniciais (entidades + vazios).
    useEffect(() => {
        if (data && def) setValues(buildInitialValues(def, data));
    }, [data, def]);

    if (loading) {
        return <div className="d-flex justify-content-center align-items-center" style={{ minHeight: "60vh" }}><Spinner color="primary" /></div>;
    }
    if (error || !data || !def) {
        return <div className="text-center text-muted py-5">Documento indisponível.</div>;
    }

    const company = data.company;
    const logoUrl = company?.logo_path
        ? (company.logo_path.startsWith("http") ? company.logo_path : PUBLIC_URL + company.logo_path)
        : null;
    const setField = (key: string, value: string) => setValues((prev) => ({ ...prev, [key]: value }));

    // Só campos visíveis (respeita visibleIf com os valores actuais).
    const isVisible = (f: DocFieldDef) => (f.visibleIf ? f.visibleIf(values) : true);
    const entityFields = def.fields.filter((f) => f.source === "entity" && isVisible(f));
    const manualFields = def.fields.filter((f) => f.source === "manual" && isVisible(f));

    // Renderiza uma célula de campo consoante o tipo (text/textarea/select/boolean).
    const renderField = (f: DocFieldDef) => {
        const type = f.type ?? (f.multiline ? "textarea" : "text");
        const val = values[f.key] ?? "";

        if (type === "boolean") {
            return (
                <Col md={12} key={f.key}>
                    <div className="form-check">
                        <input
                            className="form-check-input"
                            type="checkbox"
                            id={`docf-${f.key}`}
                            checked={val === "1"}
                            onChange={(e) => setField(f.key, e.target.checked ? "1" : "0")}
                        />
                        <Label className="form-check-label fs-13" htmlFor={`docf-${f.key}`}>{f.label}</Label>
                    </div>
                </Col>
            );
        }

        return (
            <Col md={6} key={f.key}>
                <Label className="form-label mb-1 fs-13">{f.label}</Label>
                {type === "select" ? (
                    <Input type="select" value={val} onChange={(e) => setField(f.key, e.target.value)}>
                        <option value="">—</option>
                        {(f.options ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </Input>
                ) : type === "textarea" ? (
                    <Input type="textarea" rows={2} value={val} onChange={(e) => setField(f.key, e.target.value)} />
                ) : (
                    <Input type="text" value={val} onChange={(e) => setField(f.key, e.target.value)} />
                )}
            </Col>
        );
    };

    return (
        <>
            <style>{DOC_PRINT_STYLES}</style>

            {/* Modal de campos — entidades pré-preenchidas + manuais (alguns
                condicionais/pré-preenchidos), todos editáveis. */}
            <Modal isOpen={modalOpen} toggle={goBack} size="lg" centered scrollable backdrop="static">
                <ModalHeader toggle={goBack}>{def.title} — dados do documento</ModalHeader>
                <ModalBody>
                    <p className="text-muted fs-13 mb-2">Confere e corrige os dados antes de ver o documento. Os campos das entidades vêm pré-preenchidos; os restantes preenche-os aqui (não ficam guardados).</p>
                    <h6 className="fw-semibold mb-2">Dados (pré-preenchidos)</h6>
                    <Row className="g-2 mb-3">
                        {entityFields.map((f) => renderField(f))}
                    </Row>
                    {manualFields.length > 0 && (
                        <>
                            <h6 className="fw-semibold mb-2">A preencher</h6>
                            <Row className="g-2">
                                {manualFields.map((f) => renderField(f))}
                            </Row>
                        </>
                    )}
                </ModalBody>
                <ModalFooter>
                    <button type="button" className="btn btn-light" onClick={goBack}>Cancelar</button>
                    <button type="button" className="btn btn-primary" onClick={() => setModalOpen(false)}>
                        <i className="ri-file-text-line me-1" /> Ver documento
                    </button>
                </ModalFooter>
            </Modal>

            {/* Pré-visualização + impressão — overlay full-screen (igual à ficha A4). */}
            {!modalOpen && (
                <div className="doc-overlay">
                    <div className="doc-toolbar no-print">
                        <div className="doc-toolbar-inner">
                            <button type="button" className="btn btn-light" onClick={goBack}>
                                <i className="ri-arrow-left-line me-1" /> Voltar
                            </button>
                            <div className="doc-toolbar-hint">
                                Pré-visualização. <button type="button" className="btn btn-link p-0 align-baseline" onClick={() => setModalOpen(true)}>Editar dados</button> ou <strong>Imprimir</strong> (impressora / guardar PDF).
                            </div>
                            <button type="button" className="btn btn-primary" onClick={() => window.print()}>
                                <i className="ri-printer-line me-1" /> Imprimir
                            </button>
                        </div>
                    </div>

                    <div className="doc-sheet">
                        {/* Cabeçalho dinâmico da empresa emitente (logo + dados). */}
                        <header className="doc-header">
                            {logoUrl && !logoBroken && (
                                <img src={logoUrl} alt="" className="doc-logo" onError={() => setLogoBroken(true)} />
                            )}
                            {company?.fiscal_name && <div className="doc-company-name">{company.fiscal_name}</div>}
                            {company?.nipc && <div>NIF/NIPC: {company.nipc}</div>}
                            {company?.address && <div>{company.address}</div>}
                            {(company?.postal_code || company?.locality) && (
                                <div>{[company?.postal_code, company?.locality].filter(Boolean).join(" - ")}</div>
                            )}
                            {(company?.phone || company?.mobile) && <div>Tel. {company?.phone || company?.mobile} **</div>}
                        </header>

                        <div className="doc-body">
                            {def.renderBody(values)}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

// A4 + overlay + @media print (mesma abordagem do CarPrintSheet). CSS inline
// para não vazar para o resto do painel; .no-print esconde os controlos.
const DOC_PRINT_STYLES = `
@page { size: A4 portrait; margin: 14mm; }

.doc-overlay {
    position: fixed; inset: 0; z-index: 1050;
    overflow: auto; background: #eef0f3;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.doc-toolbar {
    position: sticky; top: 0; z-index: 10;
    background: #fff; box-shadow: 0 2px 8px rgba(15,23,42,.08);
}
.doc-toolbar-inner {
    max-width: 900px; margin: 0 auto; padding: 10px 16px;
    display: flex; align-items: center; gap: 12px;
}
.doc-toolbar-hint { flex: 1; font-size: 13px; color: #6b7280; }
@media (max-width: 640px) { .doc-toolbar-hint { display: none; } }

.doc-sheet {
    max-width: 210mm; min-height: 297mm;
    margin: 20px auto; padding: 16mm;
    background: #fff; color: #1f2937;
    box-shadow: 0 6px 24px rgba(15,23,42,.12);
    font-size: 12px; line-height: 1.5;
}
.doc-header { text-align: center; font-size: 12px; line-height: 1.4; margin-bottom: 14px; }
.doc-logo { max-height: 60px; max-width: 200px; margin: 0 auto 8px; display: block; object-fit: contain; }
.doc-company-name { font-weight: 700; font-size: 14px; }
.doc-title { text-align: center; font-size: 14px; font-weight: 700; margin: 18px 0 14px; }
.doc-body .doc-p { text-align: justify; margin: 0 0 10px; }
.doc-vehicle { display: flex; gap: 24px; flex-wrap: wrap; font-weight: 600; margin: 8px 0 12px; }
.doc-signatures { display: flex; flex-direction: column; gap: 10px; margin-top: 28px; font-size: 12px; }
.doc-sign-date { font-weight: 600; }
.doc-sign-line { margin-top: 6px; }
.doc-footnotes { margin-top: 22px; font-size: 10px; color: #6b7280; }

/* Blocos do BCFT (branqueamento) — legenda, secções, linhas de dados, listas. */
.doc-legend { border: 1px solid #d1d5db; padding: 8px 10px; margin-bottom: 12px; font-size: 11px; line-height: 1.4; }
.doc-section-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .02em; margin: 14px 0 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; }
.doc-body .doc-row { margin: 0 0 4px; }
.doc-list { margin: 4px 0 10px; padding-left: 20px; }
.doc-list li { margin-bottom: 3px; }
.doc-pagebreak { break-before: page; page-break-before: always; height: 0; }

@media print {
    .no-print { display: none !important; }
    .doc-overlay { position: static; overflow: visible; background: #fff; }
    .doc-sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; min-height: auto; }
}
`;
