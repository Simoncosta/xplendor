import React, { useEffect, useMemo, useState } from "react";
import { useParams, Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Badge, Spinner, Input } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import { showAdminTicket, updateAdminTicketStatus, addAdminTicketMessage } from "helpers/laravel_helper";
import {
    ISupportTicket, SupportTicketStatus, TICKET_TYPE_META, TICKET_STATUS_META,
} from "common/models/supportTicket.model";

const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";
const absUrl = (p: string | null | undefined) => (!p ? null : p.startsWith("http") ? p : PUBLIC_URL + p);
const STATUSES: SupportTicketStatus[] = ["open", "in_review", "resolved", "closed"];

const AdminTicketDetail = () => {
    document.title = "Administração — Ticket | Xplendor";
    const { id } = useParams();

    const [ticket, setTicket] = useState<ISupportTicket | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [body, setBody] = useState("");
    const [sending, setSending] = useState(false);
    const [savingStatus, setSavingStatus] = useState(false);

    useEffect(() => {
        if (!id) return;
        let alive = true;
        setLoading(true); setError(false);
        showAdminTicket(Number(id))
            .then((r: any) => { if (alive) setTicket(r?.data ?? null); })
            .catch(() => { if (alive) setError(true); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [id]);

    const changeStatus = async (status: string) => {
        if (!id) return;
        setSavingStatus(true);
        try {
            const r: any = await updateAdminTicketStatus(Number(id), status);
            setTicket(r?.data ?? ticket);
            toast.success("Estado atualizado.");
        } catch {
            toast.error("Não foi possível mudar o estado.");
        } finally {
            setSavingStatus(false);
        }
    };

    const reply = async () => {
        if (!body.trim() || !id) return;
        setSending(true);
        try {
            const r: any = await addAdminTicketMessage(Number(id), body.trim());
            setTicket(r?.data ?? ticket);
            setBody("");
        } catch {
            toast.error("Não foi possível enviar a resposta.");
        } finally {
            setSending(false);
        }
    };

    const tm = useMemo(() => (ticket ? TICKET_TYPE_META[ticket.type] : null), [ticket]);
    const sm = useMemo(() => (ticket ? TICKET_STATUS_META[ticket.status] : null), [ticket]);

    if (loading) return <div className="page-content"><div className="d-flex justify-content-center py-5"><Spinner color="primary" /></div></div>;
    if (error || !ticket || !tm || !sm) {
        return <div className="page-content"><Container fluid><p className="text-muted py-4">Ticket não encontrado.</p><Link to="/admin">← Voltar</Link></Container></div>;
    }

    const shot = absUrl(ticket.screenshot_url);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row className="mb-3"><Col><Link to="/admin" className="text-muted"><i className="ri-arrow-left-line me-1" />Voltar aos tickets</Link></Col></Row>

                <Row>
                    <Col lg={8}>
                        <Card>
                            <CardBody>
                                <div className="d-flex align-items-start gap-2 mb-2">
                                    <span className="avatar-xs flex-shrink-0"><span className="avatar-title bg-light text-primary rounded fs-18"><i className={tm.icon} /></span></span>
                                    <div className="flex-grow-1">
                                        <h5 className="mb-1">{ticket.title}</h5>
                                        <small className="text-muted">
                                            <span className="fw-semibold">{ticket.company_name ?? `Empresa #${ticket.company_id}`}</span>
                                            {" · "}{tm.label}{ticket.author_name ? ` · ${ticket.author_name}` : ""}
                                        </small>
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
                                    <p className="text-muted fs-13">Ainda não há mensagens.</p>
                                ) : (
                                    <div className="d-flex flex-column gap-3 mb-3">
                                        {ticket.messages.map((m) => (
                                            <div key={m.id} className={"d-flex " + (m.is_staff ? "justify-content-end" : "justify-content-start")}>
                                                <div className={"p-2 px-3 rounded " + (m.is_staff ? "bg-primary text-white" : "bg-light")} style={{ maxWidth: "80%" }}>
                                                    <div className="fw-semibold fs-12 mb-1">{m.is_staff ? "Eu (Equipa XPLENDOR)" : (m.author_name || "Stand")}</div>
                                                    <div style={{ whiteSpace: "pre-wrap" }}>{m.body}</div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <div className="d-flex align-items-end gap-2">
                                    <Input type="textarea" rows={2} value={body} onChange={(e) => setBody(e.target.value)} placeholder="Responder ao stand…" />
                                    <button type="button" className="btn btn-primary flex-shrink-0" onClick={reply} disabled={sending || !body.trim()}>
                                        {sending ? <Spinner size="sm" /> : <i className="ri-send-plane-2-line" />}
                                    </button>
                                </div>
                            </CardBody>
                        </Card>
                    </Col>

                    <Col lg={4}>
                        <Card>
                            <CardBody>
                                <h6 className="mb-2">Estado</h6>
                                <Input type="select" value={ticket.status} disabled={savingStatus} onChange={(e) => changeStatus(e.target.value)}>
                                    {STATUSES.map((s) => <option key={s} value={s}>{TICKET_STATUS_META[s].label}</option>)}
                                </Input>
                                {savingStatus && <small className="text-muted d-block mt-1"><Spinner size="sm" /> A guardar…</small>}
                                {ticket.resolved_at && <small className="text-muted d-block mt-2">Resolvido em {new Date(ticket.resolved_at).toLocaleDateString("pt-PT")}</small>}
                            </CardBody>
                        </Card>
                    </Col>
                </Row>
            </Container>
        </div>
    );
};

export default AdminTicketDetail;
