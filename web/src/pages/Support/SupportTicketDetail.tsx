import React, { useEffect, useMemo, useState } from "react";
import { useParams, Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Badge, Spinner, Input } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import { showSupportTicket, addSupportTicketMessage, decideSupportTicketQuote } from "helpers/laravel_helper";
import {
    ISupportTicket, TICKET_TYPE_META, TICKET_STATUS_META, QUOTE_STATUS_META, formatEuro,
} from "common/models/supportTicket.model";

const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";
const absUrl = (p: string | null | undefined) => (!p ? null : p.startsWith("http") ? p : PUBLIC_URL + p);

const SupportTicketDetail = () => {
    document.title = "Pedido de suporte | Xplendor";
    const { id } = useParams();
    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        return a ? Number(JSON.parse(a).company_id || 0) : 0;
    }, []);

    const [ticket, setTicket] = useState<ISupportTicket | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [body, setBody] = useState("");
    const [sending, setSending] = useState(false);
    const [deciding, setDeciding] = useState(false);

    useEffect(() => {
        if (!companyId || !id) return;
        let alive = true;
        setLoading(true); setError(false);
        showSupportTicket(companyId, Number(id))
            .then((res: any) => { if (alive) setTicket(res?.data ?? null); })
            .catch(() => { if (alive) setError(true); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, id]);

    const send = async () => {
        if (!body.trim() || !id) return;
        setSending(true);
        try {
            const res: any = await addSupportTicketMessage(companyId, Number(id), body.trim());
            setTicket(res?.data ?? ticket);
            setBody("");
        } catch {
            toast.error("Não foi possível enviar a mensagem.");
        } finally {
            setSending(false);
        }
    };

    const decide = async (decision: "approve" | "reject") => {
        if (!id) return;
        if (decision === "reject" && !window.confirm("Rejeitar este orçamento? O pedido será fechado (sem renegociação).")) return;
        setDeciding(true);
        try {
            const res: any = await decideSupportTicketQuote(companyId, Number(id), decision);
            setTicket(res?.data ?? ticket);
            toast.success(decision === "approve" ? "Orçamento aprovado. Vais receber a fatura." : "Orçamento rejeitado.");
        } catch (err: any) {
            toast.error(err?.response?.data?.message || "Não foi possível registar a decisão.");
        } finally {
            setDeciding(false);
        }
    };

    if (loading) {
        return <div className="page-content"><div className="d-flex justify-content-center py-5"><Spinner color="primary" /></div></div>;
    }
    if (error || !ticket) {
        return <div className="page-content"><Container fluid><p className="text-muted py-4">Pedido não encontrado.</p><Link to="/support">← Voltar ao suporte</Link></Container></div>;
    }

    const tm = TICKET_TYPE_META[ticket.type];
    const sm = TICKET_STATUS_META[ticket.status];
    const shot = absUrl(ticket.screenshot_url);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row className="mb-3"><Col><Link to="/support" className="text-muted"><i className="ri-arrow-left-line me-1" />Voltar ao suporte</Link></Col></Row>

                <Row>
                    <Col lg={8}>
                        <Card>
                            <CardBody>
                                <div className="d-flex align-items-start gap-2 mb-2">
                                    <span className="avatar-xs flex-shrink-0"><span className="avatar-title bg-light text-primary rounded fs-18"><i className={tm.icon} /></span></span>
                                    <div className="flex-grow-1">
                                        <h5 className="mb-1">{ticket.title}</h5>
                                        <small className="text-muted">{tm.label}{ticket.author_name ? ` · ${ticket.author_name}` : ""}</small>
                                    </div>
                                    <Badge color={sm.color}>{sm.label}</Badge>
                                </div>
                                <p className="mb-3" style={{ whiteSpace: "pre-wrap" }}>{ticket.description}</p>
                                {shot && (
                                    <a href={shot} target="_blank" rel="noopener noreferrer">
                                        <img src={shot} alt="Print" className="img-fluid rounded border" style={{ maxHeight: 320 }} />
                                    </a>
                                )}
                            </CardBody>
                        </Card>

                        <Card>
                            <CardBody>
                                <h6 className="mb-3">Conversa</h6>
                                {(!ticket.messages || ticket.messages.length === 0) ? (
                                    <p className="text-muted fs-13">Ainda não há mensagens. Escreve abaixo para complementar o pedido.</p>
                                ) : (
                                    <div className="d-flex flex-column gap-3 mb-3">
                                        {ticket.messages.map((m) => (
                                            <div key={m.id} className={"d-flex " + (m.is_staff ? "justify-content-start" : "justify-content-end")}>
                                                <div
                                                    className={"p-2 px-3 rounded " + (m.is_staff ? "bg-light" : "bg-primary text-white")}
                                                    style={{ maxWidth: "80%" }}
                                                >
                                                    <div className="fw-semibold fs-12 mb-1">
                                                        {m.is_staff ? "Equipa XPLENDOR" : (m.author_name || "Eu")}
                                                    </div>
                                                    <div style={{ whiteSpace: "pre-wrap" }}>{m.body}</div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                <div className="d-flex align-items-end gap-2">
                                    <Input type="textarea" rows={2} value={body} onChange={(e) => setBody(e.target.value)} placeholder="Escreve uma mensagem…" />
                                    <button type="button" className="btn btn-primary flex-shrink-0" onClick={send} disabled={sending || !body.trim()}>
                                        {sending ? <Spinner size="sm" /> : <i className="ri-send-plane-2-line" />}
                                    </button>
                                </div>
                            </CardBody>
                        </Card>
                    </Col>

                    {/* Painel de ORÇAMENTO — só nos tickets pagos (site_change). */}
                    {ticket.type === "site_change" && (
                        <Col lg={4}>
                            <Card>
                                <CardBody>
                                    <h6 className="mb-3"><i className="ri-money-euro-circle-line text-warning me-1" />Orçamento</h6>

                                    {ticket.quote_status && (
                                        <Badge color={QUOTE_STATUS_META[ticket.quote_status].color} className="mb-3">
                                            {QUOTE_STATUS_META[ticket.quote_status].label}
                                        </Badge>
                                    )}

                                    {ticket.quote_status === "awaiting_quote" && (
                                        <p className="text-muted fs-13 mb-0">
                                            O teu pedido está a ser analisado. Vais receber aqui o orçamento para aprovar.
                                            Nenhum trabalho começa sem a tua aprovação e pagamento.
                                        </p>
                                    )}

                                    {ticket.quoted_amount != null && ticket.quote_status !== "awaiting_quote" && (
                                        <>
                                            <div className="p-3 rounded mb-3" style={{ background: "var(--vz-tertiary-bg)" }}>
                                                <div className="text-muted fs-12 text-uppercase fw-semibold mb-1">Valor</div>
                                                <div className="fs-4 fw-semibold">
                                                    {ticket.estimated_hours ?? "—"}h × {ticket.hourly_rate ?? 25}€ = {formatEuro(ticket.quoted_amount)}
                                                </div>
                                                <div className="text-muted fs-12 mt-1">Acresce IVA à taxa legal em vigor.</div>
                                            </div>

                                            {ticket.quote_status === "quoted" && (
                                                <div className="d-flex gap-2">
                                                    <button type="button" className="btn btn-success flex-grow-1" onClick={() => decide("approve")} disabled={deciding}>
                                                        {deciding ? <Spinner size="sm" /> : <><i className="ri-check-line me-1" />Aprovar</>}
                                                    </button>
                                                    <button type="button" className="btn btn-outline-danger flex-grow-1" onClick={() => decide("reject")} disabled={deciding}>
                                                        <i className="ri-close-line me-1" />Rejeitar
                                                    </button>
                                                </div>
                                            )}

                                            {ticket.quote_status === "approved" && (
                                                <p className="text-muted fs-13 mb-0">Aprovado. A aguardar a fatura e a confirmação de pagamento.</p>
                                            )}
                                            {ticket.quote_status === "paid" && (
                                                <p className="text-muted fs-13 mb-0">Pagamento confirmado. O trabalho está em execução.</p>
                                            )}
                                            {ticket.quote_status === "completed" && (
                                                <p className="text-success fs-13 mb-0"><i className="ri-check-double-line me-1" />Trabalho concluído.</p>
                                            )}
                                            {ticket.quote_status === "rejected" && (
                                                <p className="text-muted fs-13 mb-0">Orçamento rejeitado — pedido fechado.</p>
                                            )}

                                            {ticket.invoice_url && (
                                                <a href={absUrl(ticket.invoice_url) ?? "#"} target="_blank" rel="noopener noreferrer" className="btn btn-soft-primary btn-sm w-100 mt-3">
                                                    <i className="ri-file-pdf-line me-1" />Ver fatura
                                                </a>
                                            )}
                                        </>
                                    )}
                                </CardBody>
                            </Card>
                        </Col>
                    )}
                </Row>
            </Container>
        </div>
    );
};

export default SupportTicketDetail;
