import React, { useEffect, useMemo, useState } from "react";
import { useParams, Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Badge, Spinner, Input, Label } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import {
    showAdminTicket, updateAdminTicketStatus, addAdminTicketMessage,
    setAdminTicketQuote, markAdminTicketPaid, markAdminTicketCompleted,
} from "helpers/laravel_helper";
import {
    ISupportTicket, SupportTicketStatus, TICKET_TYPE_META, TICKET_STATUS_META,
    QUOTE_STATUS_META, formatEuro,
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

    // Camada de orçamento (site_change)
    const [hours, setHours] = useState("");
    const [invoiceFile, setInvoiceFile] = useState<File | null>(null);
    const [quoteBusy, setQuoteBusy] = useState(false);

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

    const saveQuote = async () => {
        if (!id) return;
        const h = Number(hours.replace(",", "."));
        if (!h || h <= 0) { toast.error("Indica as horas (ex.: 2)."); return; }
        setQuoteBusy(true);
        try {
            const r: any = await setAdminTicketQuote(Number(id), h);
            setTicket(r?.data ?? ticket);
            setHours("");
            toast.success("Orçamento enviado ao stand.");
        } catch (err: any) {
            toast.error(err?.response?.data?.message || "Não foi possível guardar o orçamento.");
        } finally {
            setQuoteBusy(false);
        }
    };

    const doMarkPaid = async () => {
        if (!id) return;
        setQuoteBusy(true);
        try {
            const fd = new FormData();
            if (invoiceFile) fd.append("invoice", invoiceFile);
            const r: any = await markAdminTicketPaid(Number(id), fd);
            setTicket(r?.data ?? ticket);
            setInvoiceFile(null);
            toast.success("Marcado como pago.");
        } catch (err: any) {
            toast.error(err?.response?.data?.errors?.invoice?.[0] || err?.response?.data?.message || "Não foi possível marcar como pago.");
        } finally {
            setQuoteBusy(false);
        }
    };

    const doComplete = async () => {
        if (!id) return;
        setQuoteBusy(true);
        try {
            const r: any = await markAdminTicketCompleted(Number(id));
            setTicket(r?.data ?? ticket);
            toast.success("Pedido concluído.");
        } catch (err: any) {
            toast.error(err?.response?.data?.message || "Não foi possível concluir.");
        } finally {
            setQuoteBusy(false);
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
                        {/* Camada de ORÇAMENTO — só nos tickets pagos (site_change). */}
                        {ticket.type === "site_change" && (
                            <Card>
                                <CardBody>
                                    <h6 className="mb-3"><i className="ri-money-euro-circle-line text-warning me-1" />Orçamento</h6>

                                    {ticket.quote_status && (
                                        <Badge color={QUOTE_STATUS_META[ticket.quote_status].color} className="mb-3">
                                            {QUOTE_STATUS_META[ticket.quote_status].label}
                                        </Badge>
                                    )}

                                    {ticket.quoted_amount != null && (
                                        <div className="p-3 rounded mb-3" style={{ background: "var(--vz-tertiary-bg)" }}>
                                            <div className="fs-5 fw-semibold">
                                                {ticket.estimated_hours ?? "—"}h × {ticket.hourly_rate ?? 25}€ = {formatEuro(ticket.quoted_amount)}
                                            </div>
                                            <div className="text-muted fs-12">Acresce IVA à taxa legal.</div>
                                        </div>
                                    )}

                                    {/* 1) Orçar (a partir de awaiting_quote; re-orçar enquanto quoted) */}
                                    {(ticket.quote_status === "awaiting_quote" || ticket.quote_status === "quoted") && (
                                        <div className="mb-2">
                                            <Label className="form-label fs-13">Horas estimadas (× {ticket.hourly_rate ?? 25}€)</Label>
                                            <div className="d-flex gap-2">
                                                <Input type="number" min={0.25} step={0.25} value={hours} onChange={(e) => setHours(e.target.value)} placeholder="ex.: 2" />
                                                <button type="button" className="btn btn-primary flex-shrink-0" onClick={saveQuote} disabled={quoteBusy}>
                                                    {quoteBusy ? <Spinner size="sm" /> : (ticket.quote_status === "quoted" ? "Re-orçar" : "Orçar")}
                                                </button>
                                            </div>
                                            {ticket.quote_status === "quoted" && <small className="text-muted d-block mt-1">A aguardar a decisão do stand.</small>}
                                        </div>
                                    )}

                                    {/* 2) Aprovado → anexar fatura + marcar pago */}
                                    {ticket.quote_status === "approved" && (
                                        <div>
                                            <Label className="form-label fs-13">Fatura (PDF, opcional)</Label>
                                            <Input type="file" accept="application/pdf" className="mb-2" onChange={(e) => setInvoiceFile(e.target.files?.[0] ?? null)} />
                                            <button type="button" className="btn btn-success w-100" onClick={doMarkPaid} disabled={quoteBusy}>
                                                {quoteBusy ? <Spinner size="sm" /> : <><i className="ri-bank-card-line me-1" />Marcar como pago</>}
                                            </button>
                                        </div>
                                    )}

                                    {/* 3) Pago/em execução → concluir */}
                                    {ticket.quote_status === "paid" && (
                                        <button type="button" className="btn btn-primary w-100" onClick={doComplete} disabled={quoteBusy}>
                                            {quoteBusy ? <Spinner size="sm" /> : <><i className="ri-check-double-line me-1" />Marcar concluído</>}
                                        </button>
                                    )}

                                    {ticket.quote_status === "rejected" && (
                                        <p className="text-muted fs-13 mb-0">O stand rejeitou — pedido fechado (sem renegociação).</p>
                                    )}
                                    {ticket.quote_status === "completed" && (
                                        <p className="text-success fs-13 mb-0"><i className="ri-check-double-line me-1" />Trabalho concluído.</p>
                                    )}

                                    {ticket.invoice_url && (
                                        <a href={absUrl(ticket.invoice_url) ?? "#"} target="_blank" rel="noopener noreferrer" className="btn btn-soft-primary btn-sm w-100 mt-3">
                                            <i className="ri-file-pdf-line me-1" />Ver fatura anexada
                                        </a>
                                    )}
                                </CardBody>
                            </Card>
                        )}

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
