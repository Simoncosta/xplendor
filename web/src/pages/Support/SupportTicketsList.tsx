import React, { useEffect, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Link, useNavigate } from "react-router-dom";
import { Container, Badge, Spinner, Modal, ModalHeader, ModalBody, ModalFooter, Input, Label } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { ToastContainer, toast } from "react-toastify";
import { getSupportTickets, createSupportTicket } from "slices/supportTickets/thunk";
import {
    ISupportTicket, SupportTicketType, TICKET_TYPE_META, TICKET_STATUS_META,
    QUOTE_STATUS_META, SITE_CHANGE_HOURLY_RATE, formatEuro,
} from "common/models/supportTicket.model";
import TicketsKanban from "Components/Common/TicketsKanban";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

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
    const navigate = useNavigate();

    const companyId = useWorkingCompanyId();

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

    const cols = useDataColumns<ISupportTicket>("equipa.suporte", [
        {
            id: "title", header: "Pedido", value: (t) => t.title, hideable: false, mobile: "title",
            cell: (t) => {
                const tm = TICKET_TYPE_META[t.type];
                const isPaid = t.type === "site_change";
                return (
                    <span className="d-inline-flex align-items-center gap-2" style={{ minWidth: 0 }}>
                        <span className="avatar-xs flex-shrink-0">
                            <span className={"avatar-title rounded fs-18 " + (isPaid ? "bg-warning-subtle text-warning" : "bg-light text-primary")}><i className={tm.icon} /></span>
                        </span>
                        <span className="fw-medium text-break">
                            {t.title}
                            {isPaid && <span className="badge bg-warning-subtle text-warning ms-2"><i className="ri-money-euro-circle-line me-1" />Pago</span>}
                        </span>
                    </span>
                );
            },
        },
        { id: "type", header: "Tipo", value: (t) => TICKET_TYPE_META[t.type].label, mobile: "subtitle" },
        { id: "amount", header: "Valor", value: (t) => (t.type === "site_change" && t.quoted_amount != null ? Number(t.quoted_amount) : undefined), cell: (t) => (t.type === "site_change" && t.quoted_amount != null ? formatEuro(t.quoted_amount) : "—"), align: "end", nowrap: true },
        { id: "messages", header: "Mensagens", value: (t) => t.messages_count ?? 0, cell: (t) => t.messages_count || "—", align: "end" },
        {
            // Nos pagos, o badge mostra o estado do ORÇAMENTO (mais informativo).
            id: "status", header: "Estado",
            value: (t) => (t.type === "site_change" && t.quote_status ? QUOTE_STATUS_META[t.quote_status].label : TICKET_STATUS_META[t.status].label),
            cell: (t) => {
                const qm = t.type === "site_change" && t.quote_status ? QUOTE_STATUS_META[t.quote_status] : null;
                const sm = TICKET_STATUS_META[t.status];
                return qm ? <Badge color={qm.color}>{qm.label}</Badge> : <Badge color={sm.color}>{sm.label}</Badge>;
            },
        },
    ] as DTColumn<ISupportTicket>[]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Suporte" breadcrumbs={[{ label: "Equipa" }]}
                    info="Os pedidos da sua empresa: ideias, melhorias, erros e sugestões." />

                <PageCard
                    title="Os meus pedidos"
                    flush={view === "list"}
                    status={!loading ? <>{tickets.length} pedido{tickets.length === 1 ? "" : "s"}</> : undefined}
                    loading={loading && tickets.length > 0}
                    actions={<>
                        <div className="xp-seg" role="tablist" aria-label="Vista">
                            <button type="button" role="tab" aria-selected={view === "list"} className={view === "list" ? "on" : ""} onClick={() => setView("list")}><i className="ri-list-check me-1" />Lista</button>
                            <button type="button" role="tab" aria-selected={view === "kanban"} className={view === "kanban" ? "on" : ""} onClick={() => setView("kanban")}><i className="ri-layout-grid-line me-1" />Kanban</button>
                        </div>
                        {view === "list" && cols.selector}
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => setOpen(true)}>
                            <i className="ri-add-line me-1" />Novo pedido
                        </button>
                    </>}
                >
                    {view === "kanban" ? (
                        tickets.length === 0
                            ? <p className="text-muted mb-0">Ainda não há pedidos. Abre o primeiro em "Novo pedido".</p>
                            // Vista Kanban SÓ LEITURA: o cliente não arrasta (o estado é gerido
                            // pelo admin). Sem coluna de empresa (é uma empresa só). Clicar abre o detalhe.
                            : <TicketsKanban tickets={tickets} readOnly showCompany={false} detailHref={(id) => `/support/${id}`} />
                    ) : (
                        <DataTable
                            columns={cols}
                            data={tickets}
                            rowKey={(t) => t.id}
                            loading={loading}
                            caption="Pedidos de suporte"
                            onRowClick={(t) => navigate(`/support/${t.id}`)}
                            empty={{ message: <>Ainda não há pedidos. Abre o primeiro em "Novo pedido".</> }}
                            rowActions={(t) => (
                                <Link to={`/support/${t.id}`} className="btn btn-outline-primary btn-sm" title="Abrir" aria-label={`Abrir: ${t.title}`} onClick={(e) => e.stopPropagation()}>
                                    <i className="ri-arrow-right-s-line" />
                                </Link>
                            )}
                        />
                    )}
                </PageCard>
            </Container>

            {/* Modal — novo pedido */}
            <Modal isOpen={open} toggle={() => setOpen(false)} centered scrollable>
                <ModalHeader toggle={() => setOpen(false)}>Novo pedido</ModalHeader>
                <ModalBody>
                    <Label className="form-label">Tipo</Label>
                    <div className="xp-seg mb-3 flex-wrap" role="radiogroup" aria-label="Tipo de pedido">
                        {TYPES.map((tp) => {
                            const m = TICKET_TYPE_META[tp];
                            return (
                                <button key={tp} type="button" role="radio" aria-checked={type === tp} className={type === tp ? "on" : ""} onClick={() => setType(tp)}>
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
                                <strong>Este é um serviço pago ({SITE_CHANGE_HOURLY_RATE}€/hora).</strong> Vai
                                receber um orçamento para aprovar: <u>nenhum trabalho começa sem a sua aprovação
                                e pagamento</u>. Descreva o que precisa e enviamos-lhe o valor.
                            </div>
                        </div>
                    )}

                    <div className="mb-3">
                        <Label className="form-label">Título</Label>
                        <Input type="text" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Resumo do pedido" />
                    </div>
                    <div className="mb-3">
                        <Label className="form-label">Descrição</Label>
                        <Input type="textarea" rows={4} value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Descreva com detalhe…" />
                    </div>

                    {type === "bug" && (
                        <div className="mb-1">
                            <Label className="form-label">Captura de ecrã (opcional)</Label>
                            <Input type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => setScreenshot(e.target.files?.[0] ?? null)} />
                            <small className="text-muted">Ajuda-nos a perceber o erro. JPG, PNG ou WebP.</small>
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
