// React
import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, CardHeader, Row, Col, Input, Spinner, Modal, ModalHeader, ModalBody } from "reactstrap";
import axios from "axios";
import { toast } from "react-toastify";
import mammoth from "mammoth";
// Molde
import { SALE_DOCUMENTS } from "../documents/registry";
import { getDocumentTemplates, getDocumentTemplateVariables } from "helpers/laravel_helper";
import { postDocx, saveBlob } from "helpers/download_helper";
import { IDocumentTemplate, IDocumentVariable } from "common/models/documentTemplate.model";

const escHtml = (s: string) =>
    s.replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c] as string));

/** Substitui os tokens {{key}} no HTML do preview:
 *  - válida preenchida → chip verde (valor);
 *  - válida vazia → chip amarelo "por preencher";
 *  - personalizada (não reconhecida) preenchida → chip azul (valor);
 *  - personalizada por preencher → chip vermelho "não reconhecida". */
function renderPreviewHtml(rawHtml: string, typed: Record<string, string>, labels: Map<string, string>): string {
    return rawHtml.replace(/\{\{([\w.]+)\}\}/g, (_m, key: string) => {
        const v = (typed[key] ?? "").trim();
        if (labels.has(key)) {
            if (v) return `<span style="background:#d1fae5;color:#065f46;padding:1px 5px;border-radius:4px">${escHtml(v)}</span>`;
            return `<span style="background:#fef3c7;color:#92400e;padding:1px 5px;border-radius:4px">${escHtml(labels.get(key) || key)} — por preencher</span>`;
        }
        if (v) return `<span style="background:#dbeafe;color:#1e40af;padding:1px 5px;border-radius:4px">${escHtml(v)}</span>`;
        return `<span style="background:#fee2e2;color:#991b1b;padding:1px 5px;border-radius:4px">{{${escHtml(key)}}} — não reconhecida</span>`;
    });
}

/**
 * DMS Caminho B — modelos de documento .docx da empresa, gerados para ESTA venda.
 * Escolhe um modelo → preenche as {{ }} com os dados da venda → download .docx.
 * Se ficarem variáveis não reconhecidas, avisa (422) em vez de entregar {{x}}.
 */
interface PreviewState {
    template: IDocumentTemplate;
    rawHtml: string;                    // HTML do mammoth com os tokens {{key}} nas lacunas
    empties: { key: string; label: string }[]; // válidas mas vazias (preenchíveis)
    unknowns: string[];                 // não reconhecidas (typo — só aviso)
}

function DocumentTemplatesBox({ companyId, carId }: { companyId: number; carId: number }) {
    const [templates, setTemplates] = useState<IDocumentTemplate[]>([]);
    const [loading, setLoading] = useState(true);
    const [generatingId, setGeneratingId] = useState<number | null>(null);
    const [downloading, setDownloading] = useState(false);

    // Catálogo (key → label) para classificar as lacunas do preview.
    const [labels, setLabels] = useState<Map<string, string>>(new Map());

    const [preview, setPreview] = useState<PreviewState | null>(null);
    const [typed, setTyped] = useState<Record<string, string>>({}); // valores EFÉMEROS

    useEffect(() => {
        let alive = true;
        setLoading(true);
        getDocumentTemplates(companyId)
            .then((res: any) => { if (alive) setTemplates((res?.data ?? []).filter((t: IDocumentTemplate) => !t.archived)); })
            .catch(() => { if (alive) setTemplates([]); })
            .finally(() => { if (alive) setLoading(false); });
        getDocumentTemplateVariables(companyId)
            .then((res: any) => {
                if (!alive) return;
                const map = new Map<string, string>();
                (res?.data ?? []).forEach((v: IDocumentVariable) => map.set(v.key, v.label));
                setLabels(map);
            })
            .catch(() => { /* sem catálogo, tudo aparece como não reconhecida */ });
        return () => { alive = false; };
    }, [companyId]);

    const genPath = (t: IDocumentTemplate) => `/companies/${companyId}/cars/${carId}/document-templates/${t.id}/generate`;

    const openPreview = async (t: IDocumentTemplate) => {
        setGeneratingId(t.id);
        try {
            const r = await postDocx(genPath(t), { preview: true }, `${t.name}.docx`);
            if (!r.ok) { toast.error("Não foi possível preparar a pré-visualização."); return; }
            let rawHtml = "";
            try {
                const { value } = await mammoth.convertToHtml({ arrayBuffer: await r.blob.arrayBuffer() });
                rawHtml = value;
            } catch {
                toast.error("Não foi possível converter o documento para pré-visualização.");
                return;
            }
            const tokens = Array.from(rawHtml.matchAll(/\{\{([\w.]+)\}\}/g)).map((m) => m[1]);
            const uniq = Array.from(new Set(tokens));
            const empties = uniq.filter((k) => labels.has(k)).map((k) => ({ key: k, label: labels.get(k) as string }));
            const unknowns = uniq.filter((k) => !labels.has(k));
            setTyped({});
            setPreview({ template: t, rawHtml, empties, unknowns });
        } finally {
            setGeneratingId(null);
        }
    };

    const download = async () => {
        if (!preview) return;
        setDownloading(true);
        try {
            const extra: Record<string, string> = {};
            Object.entries(typed).forEach(([k, v]) => { if (v && v.trim()) extra[k] = v.trim(); });
            const r = await postDocx(genPath(preview.template), { force: true, extra }, `${preview.template.name}.docx`);
            if (r.ok) { saveBlob(r.blob, r.filename); setPreview(null); }
            else toast.error("Não foi possível gerar o documento.");
        } finally {
            setDownloading(false);
        }
    };

    if (loading) {
        return <div className="d-flex align-items-center gap-2 text-muted fs-13 mb-3"><Spinner size="sm" /> A carregar modelos…</div>;
    }
    if (templates.length === 0) return null;

    const renderedHtml = preview ? renderPreviewHtml(preview.rawHtml, typed, labels) : "";

    return (
        <div className="mb-3">
            <div className="mb-2 fw-medium"><i className="ri-file-word-2-line text-primary me-1" /> Modelos de documento</div>
            <Row className="g-2">
                {templates.map((t) => (
                    <Col md={6} key={t.id}>
                        <div className="d-flex align-items-center justify-content-between border rounded p-3 h-100">
                            <div className="fw-medium">{t.name}</div>
                            <button
                                type="button"
                                className="btn btn-primary btn-sm flex-shrink-0"
                                onClick={() => openPreview(t)}
                                disabled={generatingId === t.id}
                            >
                                {generatingId === t.id ? <><Spinner size="sm" className="me-1" /> A preparar…</> : <><i className="ri-eye-line me-1" />Pré-visualizar</>}
                            </button>
                        </div>
                    </Col>
                ))}
            </Row>

            <Modal isOpen={!!preview} toggle={() => setPreview(null)} fullscreen>
                <ModalHeader toggle={() => setPreview(null)}>
                    <i className="ri-file-word-2-line text-primary me-2" />{preview?.template.name}
                </ModalHeader>
                <ModalBody className="p-0" style={{ overflow: "hidden" }}>
                    {/* Mobile: empilha com INPUTS em cima (column-reverse) e documento
                        em baixo. Desktop: DUAS COLUNAS — documento à esquerda, inputs
                        à direita. Cada coluna com scroll próprio. */}
                    <div className="d-flex flex-column-reverse flex-lg-row" style={{ height: "100%" }}>
                        {/* ESQUERDA — documento (preview em tempo real). */}
                        <div className="flex-grow-1 p-3 p-lg-4" style={{ overflowY: "auto", background: "#eef0f3" }}>
                            <div className="alert alert-info py-2 px-3 fs-13 mb-3">
                                <i className="ri-information-line me-1" />
                                Pré-visualização aproximada (confirma os dados). O <strong>.docx</strong> descarregado mantém a formatação exata do Word.
                            </div>
                            <div
                                className="border rounded bg-white p-4 mx-auto shadow-sm"
                                style={{ maxWidth: 820, minHeight: 240 }}
                                // eslint-disable-next-line react/no-danger
                                dangerouslySetInnerHTML={{ __html: renderedHtml }}
                            />
                        </div>

                        {/* DIREITA — inputs das lacunas + avisos + ações. */}
                        <div
                            className="border-start bg-body p-3 p-lg-4 d-flex flex-column"
                            style={{ width: "100%", maxWidth: 420, flexShrink: 0, overflowY: "auto" }}
                        >
                            {/* GRUPO 1 — Válidas do catálogo mas sem dado (amarelo). */}
                            {preview && preview.empties.length > 0 && (
                                <div className="mb-4">
                                    <div className="fw-semibold fs-14 mb-1"><i className="ri-edit-line me-1 text-warning" />Por preencher</div>
                                    <p className="text-muted fs-13 mb-3">Campos do sistema sem dado nesta venda. Só para este documento — não grava na ficha.</p>
                                    <div className="d-flex flex-column gap-2">
                                        {preview.empties.map((e) => (
                                            <div key={e.key}>
                                                <label className="form-label fs-13 mb-1">{e.label}</label>
                                                <Input
                                                    type="text"
                                                    value={typed[e.key] ?? ""}
                                                    onChange={(ev) => setTyped((prev) => ({ ...prev, [e.key]: ev.target.value }))}
                                                    placeholder={`Ex.: ${e.label}`}
                                                />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* GRUPO 2 — Não reconhecidas: preenchíveis à parte (personalizadas). */}
                            {preview && preview.unknowns.length > 0 && (
                                <div className="mb-4">
                                    <div className="fw-semibold fs-14 mb-1"><i className="ri-error-warning-line me-1 text-danger" />Variáveis personalizadas (não reconhecidas)</div>
                                    <p className="text-muted fs-13 mb-3">Estas variáveis não existem no sistema. Se são campos teus, preenche-os aqui (só para este documento). Se foi engano (ex.: escreveste mal <code>{"{{cliente_nome}}"}</code>), corrige no teu .docx.</p>
                                    <div className="d-flex flex-column gap-2">
                                        {preview.unknowns.map((k) => (
                                            <div key={k}>
                                                <label className="form-label fs-13 mb-1"><code>{`{{${k}}}`}</code></label>
                                                <Input
                                                    type="text"
                                                    value={typed[k] ?? ""}
                                                    onChange={(ev) => setTyped((prev) => ({ ...prev, [k]: ev.target.value }))}
                                                    placeholder="Valor para este documento"
                                                />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {preview && preview.empties.length === 0 && preview.unknowns.length === 0 && (
                                <div className="text-muted fs-13"><i className="ri-checkbox-circle-line text-success me-1" />Todos os dados foram preenchidos.</div>
                            )}

                            {/* Ações — coladas ao fundo da coluna. */}
                            <div className="mt-auto d-flex gap-2 pt-3 border-top mt-3">
                                <button type="button" className="btn btn-light flex-grow-1" onClick={() => setPreview(null)}>Fechar</button>
                                <button type="button" className="btn btn-primary flex-grow-1" onClick={download} disabled={downloading}>
                                    {downloading ? <><Spinner size="sm" className="me-1" /> A gerar…</> : <><i className="ri-download-2-line me-1" />Descarregar .docx</>}
                                </button>
                            </div>
                        </div>
                    </div>
                </ModalBody>
            </Modal>
        </div>
    );
}

interface ReportBox {
    link: string;
    phone: string | null;
    email: string | null;
    whatsappMessage: string;
    sentAt: string | null;
    sentChannel: string | null;
}

// wa.me quer só dígitos; nº PT de 9 dígitos ganha o indicativo 351.
const waNumber = (phone: string): string => {
    const clean = phone.replace(/\D/g, "");
    return clean.length === 9 ? `351${clean}` : clean;
};

/**
 * DMS Pós-venda — link do relatório + ENVIO ao cliente (Incremento 5).
 *
 * O link é estável (idempotente por venda). O stand pode: copiar/abrir, enviar
 * por WhatsApp (abre o wa.me com a mensagem pronta — manual) ou por email (o
 * XPLENDOR envia via queue). Os botões de envio só aparecem se houver
 * telefone/email do cliente (e o email respeita o consentimento no servidor).
 */
function SatisfactionLinkBox({ companyId, carId }: { companyId: number; carId: number }) {
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const [box, setBox] = useState<ReportBox | null>(null);
    const [emailState, setEmailState] = useState<"idle" | "sending" | "sent" | "error">("idle");

    useEffect(() => {
        let alive = true;
        setLoading(true);
        setFailed(false);
        axios
            .post(`/companies/${companyId}/cars/${carId}/satisfaction-report`)
            .then((res: any) => {
                if (!alive) return;
                const d = res?.data;
                if (d?.public_token) {
                    setBox({
                        link: `${window.location.origin}/r/${d.public_token}`,
                        phone: d.customer?.phone ?? null,
                        email: d.customer?.email ?? null,
                        whatsappMessage: d.whatsapp_message ?? "",
                        sentAt: d.sent_at ?? null,
                        sentChannel: d.sent_channel ?? null,
                    });
                } else setFailed(true);
            })
            .catch(() => { if (alive) setFailed(true); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, carId]);

    const copy = () => {
        if (!box) return;
        navigator.clipboard?.writeText(box.link).then(
            () => toast.success("Link copiado."),
            () => toast.info(box.link)
        );
    };

    const markSent = (channel: string) => {
        setBox((b) => (b ? { ...b, sentAt: new Date().toISOString(), sentChannel: channel } : b));
        axios.post(`/companies/${companyId}/cars/${carId}/satisfaction-report/mark-sent`, { channel })
            .catch(() => {/* registo best-effort — não bloqueia o envio manual */});
    };

    const sendWhatsApp = () => {
        if (!box?.phone) return;
        window.open(`https://wa.me/${waNumber(box.phone)}?text=${encodeURIComponent(box.whatsappMessage)}`, "_blank", "noopener");
        markSent("whatsapp");
    };

    const sendEmail = async () => {
        if (!box?.email) return;
        setEmailState("sending");
        try {
            const res: any = await axios.post(`/companies/${companyId}/cars/${carId}/satisfaction-report/send-email`);
            setEmailState("sent");
            setBox((b) => (b ? { ...b, sentAt: res?.data?.sent_at ?? new Date().toISOString(), sentChannel: "email" } : b));
            toast.success("Email enviado ao cliente.");
        } catch (err: any) {
            setEmailState("error");
            const status = err?.response?.status ?? err?.__status;
            toast.error(status === 422
                ? (err?.response?.data?.message ?? err?.message ?? "Não foi possível enviar o email.")
                : "Não foi possível enviar o email. Tente novamente.");
        }
    };

    const sentLabel = box?.sentAt
        ? `Enviado ${box.sentChannel === "email" ? "por email" : box.sentChannel === "whatsapp" ? "por WhatsApp" : ""} · ${new Date(box.sentAt).toLocaleDateString("pt-PT")}`
        : null;

    return (
        <div className="border rounded p-3 mb-3 bg-light-subtle">
            <div className="mb-1 fw-medium"><i className="ri-heart-2-line text-primary me-1" /> Relatório de satisfação (pós-venda)</div>
            <small className="text-muted d-block mb-2">O link público que o cliente abre para ver as fotos da viatura. É o mesmo sempre.</small>

            {loading ? (
                <div className="d-flex align-items-center gap-2 text-muted fs-13"><Spinner size="sm" /> A carregar o link…</div>
            ) : failed || !box ? (
                <div className="text-muted fs-13">Não foi possível obter o link do relatório.</div>
            ) : (
                <>
                    <div className="d-flex align-items-center gap-2">
                        <Input type="text" readOnly value={box.link} onFocus={(e) => e.currentTarget.select()} style={{ flex: 1, minWidth: 0 }} />
                        <button type="button" className="btn btn-light btn-sm flex-shrink-0" onClick={copy}><i className="ri-file-copy-line me-1" />Copiar</button>
                        <a className="btn btn-soft-primary btn-sm flex-shrink-0" href={box.link} target="_blank" rel="noopener noreferrer"><i className="ri-external-link-line me-1" />Abrir</a>
                    </div>

                    <div className="d-flex align-items-center gap-2 flex-wrap mt-2">
                        <span className="text-muted fs-13">Enviar ao cliente:</span>
                        {box.phone && (
                            <button type="button" className="btn btn-success btn-sm" onClick={sendWhatsApp}>
                                <i className="ri-whatsapp-line me-1" />WhatsApp
                            </button>
                        )}
                        {box.email && (
                            <button type="button" className="btn btn-primary btn-sm" onClick={sendEmail} disabled={emailState === "sending"}>
                                {emailState === "sending"
                                    ? <><Spinner size="sm" className="me-1" /> A enviar…</>
                                    : <><i className="ri-mail-send-line me-1" />{emailState === "sent" ? "Email enviado" : "Enviar por email"}</>}
                            </button>
                        )}
                        {!box.phone && !box.email && (
                            <span className="text-muted fs-13 fst-italic">A venda não tem telefone nem email do cliente (ou sem consentimento).</span>
                        )}
                        {sentLabel && <span className="badge bg-light text-muted border ms-auto">{sentLabel}</span>}
                    </div>
                </>
            )}
        </div>
    );
}

interface CarDocumentsCardProps {
    companyId: number;
    carId: number;
    /** Se a venda não tem cliente ligado, avisa (os campos do cliente vêm vazios). */
    hasCustomer?: boolean;
    /** Só há relatório de satisfação quando existe uma venda registada. */
    hasSale?: boolean;
}

// Item imprimível unificado (a ficha A4 + os documentos de venda do registry).
interface PrintableItem {
    id: string;
    title: string;
    description?: string;
    to: string;
}

/**
 * DMS Fase 3 — secção "Documentos" na tab da viatura. Lista tudo o que é
 * imprimível (ficha A4 + documentos de venda do registry). Cada item navega na
 * MESMA aba (Link SPA — nova aba parte a sessão) para o motor de impressão.
 *
 * Só apresentação (grid 2 colunas + busca). O molde fica intacto.
 */
export default function CarDocumentsCard({ companyId, carId, hasCustomer, hasSale }: CarDocumentsCardProps) {
    const [query, setQuery] = useState("");

    // Lista unificada: ficha A4 primeiro, depois os documentos do registry.
    const items = useMemo<PrintableItem[]>(() => [
        {
            id: "print-sheet",
            title: "Ficha da viatura (A4)",
            description: "Ficha técnica para imprimir e colar na viatura.",
            to: `/companies/${companyId}/cars/${carId}/print-sheet`,
        },
        ...SALE_DOCUMENTS.map((doc) => ({
            id: doc.id,
            title: doc.title,
            description: doc.description,
            to: `/companies/${companyId}/cars/${carId}/documents/${doc.id}`,
        })),
    ], [companyId, carId]);

    // Filtro por título, case-insensitive (mesma filosofia do labelOf).
    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return items;
        return items.filter((it) => it.title.toLowerCase().includes(q));
    }, [items, query]);

    return (
        <Card className="mt-3">
            <CardHeader>
                <h5 className="mb-0">Documentos</h5>
                <small className="text-muted">Tudo o que é imprimível desta viatura — a ficha A4 e os documentos de venda, preenchidos com os dados da viatura, cliente e empresa.</small>
            </CardHeader>
            <CardBody>
                {hasSale && <SatisfactionLinkBox companyId={companyId} carId={carId} />}

                {!hasCustomer && (
                    <div className="alert alert-warning py-2 px-3 fs-13 mb-3">
                        <i className="ri-information-line me-1" />
                        Esta venda ainda não tem cliente associado — os campos do cliente vêm vazios. Liga um cliente na secção "Venda concluída" (na Ficha) para os pré-preencher.
                    </div>
                )}

                {/* Busca por título — útil à medida que a lista cresce. */}
                <div className="position-relative mb-3" style={{ maxWidth: 360 }}>
                    <i className="ri-search-line position-absolute text-muted" style={{ left: 12, top: "50%", transform: "translateY(-50%)" }} />
                    <Input
                        type="text"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Procurar documento…"
                        style={{ paddingLeft: 34 }}
                    />
                </div>

                {filtered.length === 0 ? (
                    <p className="text-muted fs-13 mb-0 py-2">Nenhum documento encontrado.</p>
                ) : (
                    <Row className="g-2">
                        {filtered.map((it) => (
                            <Col md={6} key={it.id}>
                                <Link
                                    to={it.to}
                                    className="d-flex align-items-center justify-content-between text-reset border rounded p-3 text-decoration-none h-100"
                                >
                                    <div>
                                        <div className="fw-medium">{it.title}</div>
                                        {it.description && <small className="text-muted">{it.description}</small>}
                                    </div>
                                    <i className="ri-printer-line fs-18 text-primary ms-2" />
                                </Link>
                            </Col>
                        ))}
                    </Row>
                )}

                {/* DMS Caminho B — modelos .docx da empresa, gerados para esta venda. */}
                <hr className="my-3" />
                <DocumentTemplatesBox companyId={companyId} carId={carId} />
            </CardBody>
        </Card>
    );
}
