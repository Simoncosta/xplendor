import React, { useEffect, useMemo, useState } from "react";
import { useParams, Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Badge, Spinner, Input } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import { showSupportTicket, addSupportTicketMessage } from "helpers/laravel_helper";
import { ISupportTicket, TICKET_TYPE_META, TICKET_STATUS_META } from "common/models/supportTicket.model";

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
                </Row>
            </Container>
        </div>
    );
};

export default SupportTicketDetail;
