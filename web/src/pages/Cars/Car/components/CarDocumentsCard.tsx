// React
import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, CardHeader, Row, Col, Input, Spinner } from "reactstrap";
import axios from "axios";
import { toast } from "react-toastify";
// Molde
import { SALE_DOCUMENTS } from "../documents/registry";

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
            </CardBody>
        </Card>
    );
}
