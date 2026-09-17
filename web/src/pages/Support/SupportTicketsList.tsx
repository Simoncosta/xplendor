import React, { useEffect, useMemo, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Link } from "react-router-dom";
import { Card, CardBody, CardHeader, Col, Container, Row, Badge, Spinner, Modal, ModalHeader, ModalBody, ModalFooter, Input, Label } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import { getSupportTickets, createSupportTicket } from "slices/supportTickets/thunk";
import {
    ISupportTicket, SupportTicketType, TICKET_TYPE_META, TICKET_STATUS_META,
    QUOTE_STATUS_META, SITE_CHANGE_HOURLY_RATE, formatEuro,
} from "common/models/supportTicket.model";
import TicketsKanban from "Components/Common/TicketsKanban";

const selectVM = createSelector(
    [(state: any) => state.SupportTicket],
    (s) => ({
        tickets: s.data.tickets as ISupportTicket[],
        loading: s.loading.list as boolean,
        creating: s.loading.create as boolean,
    })
);

const TYPES: SupportTicketType[] = ["idea", "improvement", "bug", "suggestion", "site_change"];

const SupportTicketsList = () => {
    const dispatch: any = useDispatch();
    document.title = "Suporte | Xplendor";
    const { tickets, loading, creating } = useSelector(selectVM);

    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        return a ? Number(JSON.parse(a).company_id || 0) : 0;
    }, []);

    const [open, setOpen] = useState(false);
    const [view, setView] = useState<"list" | "kanban">("list");
    const [type, setType] = useState<SupportTicketType>("idea");
    const [title, setTitle] = useState("");
    const [description, setDescription] = useState("");
    const [screenshot, setScreenshot] = useState<File | null>(null);

    useEffect(() => { if (companyId) dispatch(getSupportTickets({ companyId })); }, [companyId, dispatch]);

    const resetForm = () => { setType("idea"); setTitle(""); setDescription(""); setScreenshot(null); };

    const submit = async () => {
        if (!title.trim()) { toast.error("Dá um título ao pedido."); return; }
        if (!description.trim()) { toast.error("Descreve o pedido."); return; }
        const fd = new FormData();
        fd.append("type", type);
        fd.append("title", title.trim());
        fd.append("description", description.trim());
        if (type === "bug" && screenshot) fd.append("screenshot", screenshot);
        try {
            await dispatch(createSupportTicket({ companyId, data: fd })).unwrap();
            toast.success("Pedido enviado. Obrigado!");
            setOpen(false); resetForm();
        } catch (err: any) {
            toast.error(err?.errors?.screenshot?.[0] || err?.message || "Não foi possível enviar o pedido.");
        }
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row className="mb-3 align-items-center">
                    <Col>
                        <h4 className="mb-1"><i className="ri-customer-service-2-line text-primary me-2" />Suporte</h4>
                        <p className="text-muted mb-0">Os pedidos da tua empresa — ideias, melhorias, bugs e sugestões.</p>
                    </Col>
                    <Col xs="auto" className="d-flex gap-2">
                        <div className="btn-group" role="group" aria-label="Vista">
                            <button type="button" className={"btn btn-sm " + (view === "list" ? "btn-primary" : "btn-outline-primary")} onClick={() => setView("list")}>
                                <i className="ri-list-check me-1" />Lista
                            </button>
                            <button type="button" className={"btn btn-sm " + (view === "kanban" ? "btn-primary" : "btn-outline-primary")} onClick={() => setView("kanban")}>
                                <i className="ri-layout-grid-line me-1" />Kanban
                            </button>
                        </div>
                        <button type="button" className="btn btn-primary" onClick={() => setOpen(true)}>
                            <i className="ri-add-line me-1" />Novo pedido
                        </button>
                    </Col>
                </Row>

                <Card>
                    <CardHeader><h5 className="mb-0">Os meus pedidos</h5></CardHeader>
                    <CardBody>
                        {loading ? (
                            <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                        ) : tickets.length === 0 ? (
                            <p className="text-muted mb-0">Ainda não há pedidos. Abre o primeiro em "Novo pedido".</p>
                        ) : view === "kanban" ? (
                            // Vista Kanban SÓ LEITURA: o cliente não arrasta (o estado é gerido
                            // pelo admin). Sem coluna de empresa (é uma empresa só). Clicar abre o detalhe.
                            <TicketsKanban
                                tickets={tickets}
                                readOnly
                                showCompany={false}
                                detailHref={(id) => `/support/${id}`}
                            />
                        ) : (
                            <div className="d-flex flex-column gap-2">
                                {tickets.map((t) => {
                                    const tm = TICKET_TYPE_META[t.type];
                                    const isPaid = t.type === "site_change";
                                    const qm = isPaid && t.quote_status ? QUOTE_STATUS_META[t.quote_status] : null;
                                    const sm = TICKET_STATUS_META[t.status];
                                    return (
                                        <Link key={t.id} to={`/support/${t.id}`} className="d-flex align-items-center gap-3 border rounded p-3 text-reset text-decoration-none">
                                            <span className="avatar-xs flex-shrink-0">
                                                <span className={"avatar-title rounded fs-18 " + (isPaid ? "bg-warning-subtle text-warning" : "bg-light text-primary")}><i className={tm.icon} /></span>
                                            </span>
                                            <div className="flex-grow-1 min-w-0">
                                                <div className="fw-medium text-truncate">
                                                    {t.title}
                                                    {isPaid && <span className="badge bg-warning-subtle text-warning ms-2"><i className="ri-money-euro-circle-line me-1" />Pago</span>}
                                                </div>
                                                <small className="text-muted">
                                                    {tm.label}
                                                    {isPaid && t.quoted_amount != null ? ` · ${formatEuro(t.quoted_amount)}` : ""}
                                                    {t.messages_count ? ` · ${t.messages_count} mensagem(ns)` : ""}
                                                </small>
                                            </div>
                                            {/* Nos pagos, o badge mostra o estado do ORÇAMENTO (mais informativo). */}
                                            {qm
                                                ? <Badge color={qm.color} className="flex-shrink-0">{qm.label}</Badge>
                                                : <Badge color={sm.color} className="flex-shrink-0">{sm.label}</Badge>}
                                            <i className="ri-arrow-right-s-line fs-18 text-muted flex-shrink-0" />
                                        </Link>
                                    );
                                })}
                            </div>
                        )}
                    </CardBody>
                </Card>
            </Container>

            {/* Modal — novo pedido */}
            <Modal isOpen={open} toggle={() => setOpen(false)} centered scrollable>
                <ModalHeader toggle={() => setOpen(false)}>Novo pedido</ModalHeader>
                <ModalBody>
                    <Label className="form-label">Tipo</Label>
                    <div className="d-flex flex-wrap gap-2 mb-3">
                        {TYPES.map((tp) => {
                            const m = TICKET_TYPE_META[tp];
                            const active = type === tp;
                            return (
                                <button
                                    key={tp}
                                    type="button"
                                    className={"btn btn-sm " + (active ? "btn-primary" : "btn-light")}
                                    onClick={() => setType(tp)}
                                >
                                    <i className={m.icon + " me-1"} />{m.label}
                                </button>
                            );
                        })}
                    </div>

                    {/* Aviso de SERVIÇO PAGO — à partida, antes de descrever/abrir. */}
                    {type === "site_change" && (
                        <div className="alert alert-warning d-flex gap-2 mb-3" role="alert">
                            <i className="ri-money-euro-circle-line fs-18 flex-shrink-0" />
                            <div className="fs-13">
                                <strong>Este é um serviço pago ({SITE_CHANGE_HOURLY_RATE}€/hora).</strong> Vais
                                receber um orçamento para aprovar — <u>nenhum trabalho começa sem a tua aprovação
                                e pagamento</u>. Descreve o que precisas e enviamos-te o valor.
                            </div>
                        </div>
                    )}

                    <div className="mb-3">
                        <Label className="form-label">Título</Label>
                        <Input type="text" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Resumo do pedido" />
                    </div>
                    <div className="mb-3">
                        <Label className="form-label">Descrição</Label>
                        <Input type="textarea" rows={4} value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Descreve com detalhe…" />
                    </div>

                    {type === "bug" && (
                        <div className="mb-1">
                            <Label className="form-label">Print da tela (opcional)</Label>
                            <Input type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => setScreenshot(e.target.files?.[0] ?? null)} />
                            <small className="text-muted">Ajuda-nos a perceber o bug. JPG, PNG ou WebP.</small>
                        </div>
                    )}
                </ModalBody>
                <ModalFooter>
                    <button type="button" className="btn btn-light" onClick={() => setOpen(false)}>Cancelar</button>
                    <button type="button" className="btn btn-primary" onClick={submit} disabled={creating}>
                        {creating ? <><Spinner size="sm" className="me-1" /> A enviar…</> : "Enviar pedido"}
                    </button>
                </ModalFooter>
            </Modal>
        </div>
    );
};

export default SupportTicketsList;
