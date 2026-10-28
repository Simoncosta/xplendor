import React, { useEffect, useMemo, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Badge, Spinner, Input, Label } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import ReasonButton from "Components/Common/ReasonButton";
import XSelect from "Components/Common/Select";
import ConfirmModal from "Components/Common/ConfirmModal";
import Skeleton from "Components/Common/Skeleton";
import {
    showAdminTicket, updateAdminTicketStatus, addAdminTicketMessage,
    setAdminTicketQuote, markAdminTicketPaid, markAdminTicketCompleted, updateAdminTicketTask,
    updateAdminTicketMessage, deleteAdminTicketMessage, deleteAdminTicketScreenshot, uploadAdminTicketInvoice,
    deleteAdminTicketInvoice, unmarkAdminTicketPaid, deleteAdminTicket,
} from "helpers/laravel_helper";
import {
    ISupportTicket, ISupportTicketMessage, SupportTicketStatus, SupportTicketType, TICKET_TYPE_META, TICKET_STATUS_META,
    QUOTE_STATUS_META, PAYMENT_STATUS_META, formatEuro,
} from "common/models/supportTicket.model";
import { useTicketTypeChange } from "Components/Common/useTicketTypeChange";
import TicketTasksCard from "Components/Common/TicketTasksCard";
import SetupLinkModal from "pages/Companies/CompanyProfile/setupLink/SetupLinkModal";
import type { SetupStepKey } from "common/models/setupLink.model";

/** Chave da tarefa de arranque => passo do link de configuração. */
const TASK_STEP: Record<string, SetupStepKey> = { social_access: "social", meta_ads_access: "meta_ads", ga4_access: "ga4" };

const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";
const absUrl = (p: string | null | undefined) => (!p ? null : p.startsWith("http") ? p : PUBLIC_URL + p);
const STATUSES: SupportTicketStatus[] = ["open", "in_review", "resolved", "closed"];
const TYPES: SupportTicketType[] = ["idea", "improvement", "bug", "suggestion", "site_change"];
const fmtDateTime = (s: string | null | undefined) =>
    s ? new Date(s).toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "";
const fmtDate = (s: string | null | undefined) => (s ? new Date(s).toLocaleDateString("pt-PT") : "—");
/** A mensagem de um erro da API (o interceptor devolve o corpo: message e errors). */
const errMsg = (err: any, fallback: string): string => {
    const first = err?.errors ? Object.values(err.errors as Record<string, string[]>)[0]?.[0] : null;
    return first || err?.message || fallback;
};

type Confirm = { title: string; message: string; confirmText: string; variant?: "danger" | "warning"; run: () => Promise<void> };

const AdminTicketDetail = () => {
    document.title = "Administração: ticket | Xplendor";
    const { id } = useParams();
    const navigate = useNavigate();

    const [ticket, setTicket] = useState<ISupportTicket | null>(null);
    // Link de configuração gerado a partir de uma tarefa de acesso do ticket de arranque.
    const [setupOpen, setSetupOpen] = useState(false);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [body, setBody] = useState("");
    const [sending, setSending] = useState(false);
    const [savingStatus, setSavingStatus] = useState(false);

    // Camada de orçamento (site_change)
    const [hours, setHours] = useState("");
    const [invoiceFile, setInvoiceFile] = useState<File | null>(null);
    const [quoteBusy, setQuoteBusy] = useState(false);

    // Editar uma mensagem na própria thread, e as confirmações (apagar, retirar, anular).
    const [editing, setEditing] = useState<{ id: number; body: string } | null>(null);
    const [editBusy, setEditBusy] = useState(false);
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [confirmBusy, setConfirmBusy] = useState(false);
    const runConfirm = async () => {
        if (!confirm) return;
        setConfirmBusy(true);
        try { await confirm.run(); setConfirm(null); } finally { setConfirmBusy(false); }
    };
    /** Corre uma ação que devolve o ticket, com a mensagem de sucesso ou o erro da API. */
    const act = async (call: () => Promise<any>, ok: string, fail: string) => {
        try {
            const r: any = await call();
            applyTicket(r?.data);
            toast.success(ok);
        } catch (err: any) {
            toast.error(errMsg(err, fail));
        }
    };

    // As ações (estado, mensagem, orçamento) devolvem o ticket sem o histórico de tipo:
    // mantém o que já estava carregado.
    const applyTicket = (next: ISupportTicket | null | undefined) =>
        setTicket((prev) => (next ? { ...next, type_changes: next.type_changes ?? prev?.type_changes } : prev));

    // Mudança de tipo (só a equipa XPLENDOR): confirmação quando anula um orçamento.
    const { requestTypeChange, modal: typeChangeModal, busy: typeBusy } = useTicketTypeChange((updated) => setTicket(updated));

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
            applyTicket(r?.data);
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
            applyTicket(r?.data);
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
            applyTicket(r?.data);
            setHours("");
            toast.success("Orçamento enviado ao stand.");
        } catch (err: any) {
            toast.error(errMsg(err, "Não foi possível guardar o orçamento."));
        } finally {
            setQuoteBusy(false);
        }
    };

    // A fatura é uma ação própria (anexar ou trocar), separada do pagamento.
    const uploadInvoice = async () => {
        if (!id || !invoiceFile) return;
        setQuoteBusy(true);
        const fd = new FormData();
        fd.append("invoice", invoiceFile);
        await act(() => uploadAdminTicketInvoice(Number(id), fd), "Fatura anexada. O Administrador da empresa recebe um e-mail.", "Não foi possível anexar a fatura.");
        setInvoiceFile(null);
        setQuoteBusy(false);
    };

    const doMarkPaid = async () => {
        if (!id) return;
        setQuoteBusy(true);
        await act(() => markAdminTicketPaid(Number(id), new FormData()), "Pagamento marcado.", "Não foi possível marcar como pago.");
        setQuoteBusy(false);
    };

    const saveEdit = async () => {
        if (!id || !editing || !editing.body.trim()) return;
        setEditBusy(true);
        try {
            const r: any = await updateAdminTicketMessage(Number(id), editing.id, editing.body.trim());
            applyTicket(r?.data);
            setEditing(null);
            toast.success("Mensagem editada.");
        } catch (err: any) {
            toast.error(errMsg(err, "Não foi possível editar a mensagem."));
        } finally {
            setEditBusy(false);
        }
    };

    const doComplete = async () => {
        if (!id) return;
        setQuoteBusy(true);
        try {
            const r: any = await markAdminTicketCompleted(Number(id));
            applyTicket(r?.data);
            toast.success("Pedido concluído.");
        } catch (err: any) {
            toast.error(errMsg(err, "Não foi possível concluir."));
        } finally {
            setQuoteBusy(false);
        }
    };

    const tm = useMemo(() => (ticket ? TICKET_TYPE_META[ticket.type] : null), [ticket]);
    const sm = useMemo(() => (ticket ? TICKET_STATUS_META[ticket.status] : null), [ticket]);

    if (loading) {
        return (
            <div className="page-content"><Container fluid>
                <Row>
                    <Col xxl={3}><Card><CardBody><Skeleton variant="lines" lines={8} /></CardBody></Card></Col>
                    <Col xxl={9}><Card><CardBody><Skeleton variant="block" height={320} /></CardBody></Card></Col>
                </Row>
            </Container></div>
        );
    }
    if (error || !ticket || !tm || !sm) {
        return (
            <div className="page-content"><Container fluid>
                <PageHeader title="Ticket" breadcrumbs={[{ label: "Administração" }, { label: "Tickets", to: "/admin" }]} />
                <Card><CardBody>
                    <p className="text-muted mb-2">Ticket não encontrado.</p>
                    <button className="btn btn-primary" onClick={() => navigate("/admin")}>Voltar aos tickets</button>
                </CardBody></Card>
            </Container></div>
        );
    }

    const shot = absUrl(ticket.screenshot_url);
    const can = ticket.team?.can ?? null;
    const deleted = !!ticket.team?.deleted_at;
    const work = ticket.quote_status === "approved" || ticket.quote_status === "paid" || ticket.quote_status === "completed";
    const payment = ticket.payment_status ? PAYMENT_STATUS_META[ticket.payment_status] : null;

    return (
        <div className="page-content">
            <ToastContainer />
            {typeChangeModal}
            <ConfirmModal isOpen={!!confirm} title={confirm?.title ?? ""} message={confirm?.message ?? ""} confirmText={confirm?.confirmText}
                variant={confirm?.variant ?? "danger"} loading={confirmBusy} onConfirm={runConfirm} onCancel={() => setConfirm(null)} />
            <Container fluid>
                <PageHeader title={`#${ticket.id} ${ticket.title}`} crumbLabel={`#${ticket.id}`}
                    breadcrumbs={[{ label: "Administração" }, { label: "Tickets", to: "/admin" }]} />
                {deleted && (
                    <div className="alert alert-secondary d-flex gap-2 align-items-start" data-testid="ticket-deleted">
                        <i className="ri-delete-bin-line fs-16" />
                        <span>Pedido apagado em {fmtDateTime(ticket.team?.deleted_at)}{ticket.team?.deleted_by_name ? ` por ${ticket.team.deleted_by_name}` : ""}. A cliente já não o vê; aqui fica só para consulta.</span>
                    </div>
                )}
                <Row>
                    {/* Barra lateral — visual TaskDetails: detalhes (table-card) + orçamento. */}
                    <Col xxl={3}>
                        <Card className="mb-3">
                            <CardBody>
                                {/* O admin pode mudar o estado aqui (como o "board" do template). */}
                                <div className="mb-4">
                                    <Label className="form-label">Estado</Label>
                                    <XSelect<string> ariaLabel="Estado" value={ticket.status} disabled={savingStatus} onChange={(v) => changeStatus(v)}
                                        options={STATUSES.map((st) => ({ value: st, label: TICKET_STATUS_META[st].label }))} />
                                    {savingStatus && <small className="text-muted d-block mt-1"><Spinner size="sm" /> A guardar…</small>}
                                </div>
                                {/* Tipo: com orçamento orçado ou rejeitado pede confirmação; aprovado, pago ou concluído fica bloqueado. */}
                                <div className="mb-4">
                                    <Label className="form-label">Tipo</Label>
                                    {ticket.type === "onboarding" ? (
                                        // Ticket de arranque (criado por um orçamento aceite): o tipo não muda.
                                        <div className="form-control bg-light"><i className={`${TICKET_TYPE_META.onboarding.icon} me-1`} />{TICKET_TYPE_META.onboarding.label}</div>
                                    ) : (
                                        <XSelect<string> ariaLabel="Tipo" value={ticket.type} disabled={typeBusy}
                                            onChange={(v) => requestTypeChange(ticket, v as SupportTicketType)}
                                            options={TYPES.map((t) => ({ value: t, label: TICKET_TYPE_META[t].label }))} />
                                    )}
                                </div>
                                <div className="table-card">
                                    <table className="table mb-0">
                                        <tbody>
                                            <tr><td className="fw-medium">Nº ticket</td><td>#{ticket.id}</td></tr>
                                            <tr><td className="fw-medium">Tipo</td><td><i className={tm.icon + " me-1"} />{tm.label}</td></tr>
                                            <tr><td className="fw-medium">Estado</td><td><span className={`badge bg-${sm.color}-subtle text-${sm.color}`}>{sm.label}</span></td></tr>
                                            <tr><td className="fw-medium">Empresa</td><td>{ticket.company_name ?? `Empresa #${ticket.company_id}`}</td></tr>
                                            <tr><td className="fw-medium">Aberto por</td><td>{ticket.author_name ?? "Sem nome"}
                                                {ticket.team?.impersonator_name && <div className="text-muted fs-12">Escrito por {ticket.team.impersonator_name}, na sessão como cliente</div>}</td></tr>
                                            <tr><td className="fw-medium">Criado</td><td>{fmtDate(ticket.created_at)}</td></tr>
                                            {ticket.resolved_at && <tr><td className="fw-medium">Resolvido</td><td>{fmtDate(ticket.resolved_at)}</td></tr>}
                                        </tbody>
                                    </table>
                                </div>
                            </CardBody>
                        </Card>

                        {/* Camada de ORÇAMENTO — só nos tickets pagos (site_change). */}
                        {ticket.type === "site_change" && (
                            <Card className="mb-3">
                                <CardBody>
                                    <h6 className="card-title mb-3"><i className="ri-money-euro-circle-line text-warning me-1" />Orçamento</h6>

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

                                    {/* 2) Aprovado: o trabalho, a fatura e o pagamento, cada um à parte. */}
                                    {work && !deleted && (
                                        <div className="vstack gap-3" data-testid="admin-billing">
                                            <div className="d-flex justify-content-between align-items-center fs-13">
                                                <span className="text-muted">Trabalho</span>
                                                <span className="fw-medium">{ticket.quote_status === "completed" ? "Concluído" : "Em execução"}</span>
                                            </div>
                                            {payment && (
                                                <div className="d-flex justify-content-between align-items-center fs-13">
                                                    <span className="text-muted">Pagamento</span>
                                                    <span className={`badge bg-${payment.color}-subtle text-${payment.color}`}><i className={`${payment.icon} me-1`} />{payment.label}</span>
                                                </div>
                                            )}

                                            {/* Fatura: anexar ou trocar (só a que é dele) e retirar. */}
                                            <div>
                                                <Label className="form-label fs-13 mb-1">Fatura</Label>
                                                {ticket.invoice_url && (
                                                    <div className="d-flex gap-2 mb-2">
                                                        <a href={absUrl(ticket.invoice_url) ?? "#"} target="_blank" rel="noopener noreferrer" className="btn btn-outline-primary btn-sm flex-grow-1">
                                                            <i className="ri-file-pdf-line me-1" />Ver fatura
                                                        </a>
                                                        {can?.change_invoice && (
                                                            <button type="button" className="btn btn-outline-danger btn-sm" aria-label="Retirar a fatura" onClick={() => setConfirm({
                                                                title: "Retirar a fatura", confirmText: "Retirar",
                                                                message: "A fatura deixa de estar disponível para a cliente e aparece uma mensagem da equipa no pedido. O ficheiro é apagado ao fim de 30 dias. O pagamento não muda.",
                                                                run: () => act(() => deleteAdminTicketInvoice(ticket.id), "Fatura retirada.", "Não foi possível retirar a fatura."),
                                                            })}><i className="ri-delete-bin-line" /></button>
                                                        )}
                                                    </div>
                                                )}
                                                {ticket.invoiced_at && (
                                                    <small className="text-muted d-block mb-2">Emitida em {fmtDate(ticket.invoiced_at)}{ticket.team?.invoice_by_name ? ` por ${ticket.team.invoice_by_name}` : ""}</small>
                                                )}
                                                {(can?.change_invoice ?? true) ? (
                                                    <div className="d-flex gap-2">
                                                        <Input type="file" accept="application/pdf" bsSize="sm" aria-label="Fatura em PDF" onChange={(e) => setInvoiceFile(e.target.files?.[0] ?? null)} />
                                                        <ReasonButton color="primary" size="sm" className="flex-shrink-0" onClick={uploadInvoice} disabled={quoteBusy}
                                                            reason={!invoiceFile ? "Escolha primeiro o PDF da fatura." : null}>
                                                            {quoteBusy ? <Spinner size="sm" /> : ticket.invoice_url ? "Trocar" : "Anexar"}
                                                        </ReasonButton>
                                                    </div>
                                                ) : (
                                                    <small className="text-muted">A fatura foi anexada por outra pessoa da equipa: só ela a troca ou retira.</small>
                                                )}
                                            </div>

                                            {/* Pagamento: marcar e anular (para corrigir enganos). */}
                                            {ticket.paid_at ? (
                                                <div className="d-flex justify-content-between align-items-center gap-2">
                                                    <small className="text-muted">Pago em {fmtDate(ticket.paid_at)}{ticket.team?.paid_by_name ? ` (${ticket.team.paid_by_name})` : ""}</small>
                                                    <button type="button" className="btn btn-link btn-sm text-danger p-0" onClick={() => setConfirm({
                                                        title: "Anular o pagamento", confirmText: "Anular", variant: "warning",
                                                        message: "O pedido volta a ficar por pagar. A alteração fica registada na auditoria.",
                                                        run: () => act(() => unmarkAdminTicketPaid(ticket.id), "Pagamento anulado.", "Não foi possível anular o pagamento."),
                                                    })}>Anular pago</button>
                                                </div>
                                            ) : (
                                                <button type="button" className="btn btn-success w-100" onClick={doMarkPaid} disabled={quoteBusy}>
                                                    {quoteBusy ? <Spinner size="sm" /> : <><i className="ri-bank-card-line me-1" />Marcar como pago</>}
                                                </button>
                                            )}

                                            {/* Concluir: antes ou depois do pagamento. */}
                                            {ticket.quote_status !== "completed" && (
                                                <button type="button" className="btn btn-primary w-100" onClick={doComplete} disabled={quoteBusy}>
                                                    {quoteBusy ? <Spinner size="sm" /> : <><i className="ri-check-double-line me-1" />Marcar concluído</>}
                                                </button>
                                            )}
                                        </div>
                                    )}

                                    {ticket.quote_status === "rejected" && (
                                        <p className="text-muted fs-13 mb-0">O stand rejeitou: pedido fechado (sem renegociação).</p>
                                    )}
                                </CardBody>
                            </Card>
                        )}

                        {/* Histórico das mudanças de tipo (o orçamento anulado fica aqui registado). */}
                        {(ticket.type_changes?.length ?? 0) > 0 && (
                            <Card className="mb-3">
                                <CardBody>
                                    <h6 className="card-title mb-3"><i className="ri-history-line text-primary me-1" />Histórico do tipo</h6>
                                    <ul className="list-unstyled mb-0 vstack gap-3">
                                        {ticket.type_changes!.map((c) => (
                                            <li key={c.id} className="fs-13">
                                                <div className="fw-medium">
                                                    {TICKET_TYPE_META[c.from_type]?.label ?? c.from_type}
                                                    <i className="ri-arrow-right-line mx-1 text-muted" />
                                                    {TICKET_TYPE_META[c.to_type]?.label ?? c.to_type}
                                                </div>
                                                {c.previous_quoted_amount != null ? (
                                                    <div className="text-warning">
                                                        Orçamento anulado: {formatEuro(c.previous_quoted_amount)}
                                                        {c.previous_estimated_hours != null ? ` · ${String(c.previous_estimated_hours).replace(".", ",")} h` : ""}
                                                        {c.previous_quote_status ? ` (${QUOTE_STATUS_META[c.previous_quote_status].label})` : ""}
                                                    </div>
                                                ) : c.previous_quote_status ? (
                                                    <div className="text-muted">Estava: {QUOTE_STATUS_META[c.previous_quote_status].label}</div>
                                                ) : null}
                                                <div className="text-muted fs-12">{fmtDateTime(c.created_at)}{c.changed_by_name ? ` · ${c.changed_by_name}` : ""}</div>
                                            </li>
                                        ))}
                                    </ul>
                                </CardBody>
                            </Card>
                        )}

                        {/* Apagar um pedido criado por engano (só o que é dele, sem mensagens da cliente nem trabalho aprovado). */}
                        {!deleted && can && (can.delete || can.delete_blocked_by) && (
                            <ReasonButton color="outline-danger" className="w-100 mb-3" reason={can.delete ? null : can.delete_blocked_by}
                                onClick={() => setConfirm({
                                    title: "Apagar o pedido", confirmText: "Apagar",
                                    message: "O pedido sai das listas e a cliente deixa de o ver. Fica no filtro Apagados, só para consulta.",
                                    run: async () => {
                                        try {
                                            await deleteAdminTicket(ticket.id);
                                            toast.success("Pedido apagado.");
                                            navigate("/admin");
                                        } catch (err: any) {
                                            toast.error(errMsg(err, "Não foi possível apagar o pedido."));
                                        }
                                    },
                                })}>
                                <i className="ri-delete-bin-line me-1" />Apagar pedido
                            </ReasonButton>
                        )}
                    </Col>

                    {/* Coluna principal — descrição (Summary) + thread de conversa. */}
                    <Col xxl={9}>
                        <Card>
                            <CardBody>
                                <div className="d-flex align-items-start gap-2 mb-3">
                                    <span className="avatar-sm flex-shrink-0"><span className="avatar-title bg-light text-primary rounded fs-20"><i className={tm.icon} /></span></span>
                                    <div className="flex-grow-1">
                                        <h5 className="mb-1">{ticket.title}</h5>
                                        <small className="text-muted">
                                            <span className="fw-semibold">{ticket.company_name ?? `Empresa #${ticket.company_id}`}</span>
                                            {" · "}{tm.label}{ticket.author_name ? ` · ${ticket.author_name}` : ""}
                                        </small>
                                    </div>
                                    <Badge color={sm.color}>{sm.label}</Badge>
                                </div>
                                <div className="text-muted">
                                    <h6 className="mb-2 text-uppercase">Descrição</h6>
                                    <p className="mb-3" style={{ whiteSpace: "pre-wrap" }}>{ticket.description}</p>
                                </div>
                                {shot && (
                                    <div>
                                        <a href={shot} target="_blank" rel="noopener noreferrer">
                                            <img src={shot} alt="Print" className="img-fluid rounded border" style={{ maxHeight: 320 }} />
                                        </a>
                                        {can?.remove_screenshot && !deleted && (
                                            <div className="mt-2">
                                                <button type="button" className="btn btn-link btn-sm text-danger p-0" onClick={() => setConfirm({
                                                    title: "Retirar o anexo", confirmText: "Retirar",
                                                    message: "O ficheiro é apagado já e aparece uma mensagem da equipa no pedido.",
                                                    run: () => act(() => deleteAdminTicketScreenshot(ticket.id), "Anexo retirado.", "Não foi possível retirar o anexo."),
                                                })}><i className="ri-delete-bin-line me-1" />Retirar anexo</button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </CardBody>
                        </Card>

                        {ticket.tasks && ticket.tasks.length > 0 && (
                            <TicketTasksCard tasks={ticket.tasks} onSetupLink={ticket.type === "onboarding" ? () => setSetupOpen(true) : undefined} onToggle={async (task, done) => {
                                try {
                                    const r: any = await updateAdminTicketTask(ticket.id, task.id, done);
                                    if (r?.data) setTicket((prev) => (prev ? { ...prev, tasks: r.data.tasks } : prev));
                                } catch (e: any) {
                                    toast.error(e?.message ?? "Não foi possível atualizar a tarefa.");
                                }
                            }} />
                        )}

                        {ticket.type === "onboarding" && (
                            <SetupLinkModal isOpen={setupOpen} onClose={() => { setSetupOpen(false); }} companyId={ticket.setup_company_id ?? null} supportTicketId={ticket.id}
                                presetSteps={Array.from(new Set((ticket.tasks ?? []).filter((t) => !t.done && t.task_key && TASK_STEP[t.task_key]).map((t) => TASK_STEP[t.task_key as string])))} />
                        )}

                        <Card>
                            <CardBody>
                                <h6 className="mb-3 text-uppercase">Conversa</h6>
                                {(!ticket.messages || ticket.messages.length === 0) ? (
                                    <p className="text-muted fs-13">Ainda não há mensagens.</p>
                                ) : (
                                    <div className="d-flex flex-column gap-3 mb-3">
                                        {ticket.messages.map((m) => (
                                            <AdminMessage key={m.id} m={m} readOnly={deleted}
                                                editing={editing?.id === m.id ? editing.body : null} editBusy={editBusy}
                                                onEdit={() => setEditing({ id: m.id, body: m.body ?? "" })}
                                                onEditChange={(body) => setEditing({ id: m.id, body })}
                                                onEditCancel={() => setEditing(null)} onEditSave={saveEdit}
                                                onDelete={() => setConfirm({
                                                    title: "Apagar a mensagem", confirmText: "Apagar",
                                                    message: "A cliente passa a ver \u201cMensagem apagada pela equipa XPLENDOR\u201d, com a data. Os e-mails já enviados não se desenviam.",
                                                    run: () => act(() => deleteAdminTicketMessage(ticket.id, m.id), "Mensagem apagada.", "Não foi possível apagar a mensagem."),
                                                })} />
                                        ))}
                                    </div>
                                )}
                                {!deleted && <div className="d-flex align-items-end gap-2">
                                    <Input type="textarea" rows={2} value={body} onChange={(e) => setBody(e.target.value)} placeholder="Responder ao stand…" />
                                    <ReasonButton color="outline-primary" className="flex-shrink-0" onClick={reply} disabled={sending} aria-label="Enviar resposta"
                                        reason={!body.trim() ? "Escreva a resposta primeiro." : null}>
                                        {sending ? <Spinner size="sm" /> : <i className="ri-send-plane-2-line" />}
                                    </ReasonButton>
                                </div>}
                            </CardBody>
                        </Card>
                    </Col>
                </Row>
            </Container>
        </div>
    );
};

/**
 * Uma mensagem na thread do root: quem escreveu (o nome, e se foi na sessão como cliente), as marcas
 * "editada" e "apagada", o texto antes de cada edição, e editar ou apagar as que são dele.
 */
function AdminMessage({ m, readOnly, editing, editBusy, onEdit, onEditChange, onEditCancel, onEditSave, onDelete }: {
    m: ISupportTicketMessage; readOnly: boolean; editing: string | null; editBusy: boolean;
    onEdit: () => void; onEditChange: (body: string) => void; onEditCancel: () => void; onEditSave: () => void; onDelete: () => void;
}) {
    const [showVersions, setShowVersions] = useState(false);
    const versions = m.team?.versions ?? [];
    const who = m.is_staff
        ? `Equipa XPLENDOR${m.author_name ? ` · ${m.author_name}` : ""}${m.team?.impersonated ? " (na sessão como cliente)" : ""}`
        : (m.author_name || "Stand");
    const canEdit = !readOnly && !!m.team?.can_edit;
    return (
        <div className={"d-flex " + (m.is_staff ? "justify-content-end" : "justify-content-start")} data-testid={`admin-message-${m.id}`}>
            <div className={"p-2 px-3 rounded " + (m.deleted ? "bg-light border" : m.is_staff ? "bg-primary text-white" : "bg-light")} style={{ maxWidth: "80%", minWidth: editing !== null ? "60%" : undefined }}>
                <div className="d-flex justify-content-between gap-3 fw-semibold fs-12 mb-1">
                    <span>{who}</span>
                    {canEdit && editing === null && (
                        <span className="d-inline-flex gap-2">
                            <button type="button" className="btn btn-link btn-sm p-0 text-reset" aria-label="Editar a mensagem" onClick={onEdit}><i className="ri-pencil-line" /></button>
                            <button type="button" className="btn btn-link btn-sm p-0 text-reset" aria-label="Apagar a mensagem" onClick={onDelete}><i className="ri-delete-bin-line" /></button>
                        </span>
                    )}
                </div>
                {editing !== null ? (
                    <div>
                        <Input type="textarea" rows={3} value={editing} onChange={(e) => onEditChange(e.target.value)} aria-label="Texto da mensagem" />
                        <div className="d-flex justify-content-end gap-2 mt-2">
                            <button type="button" className="btn btn-light btn-sm" onClick={onEditCancel} disabled={editBusy}>Cancelar</button>
                            <ReasonButton color="success" size="sm" onClick={onEditSave} disabled={editBusy} reason={!editing.trim() ? "Escreva o texto da mensagem." : null}>
                                {editBusy ? <Spinner size="sm" /> : "Guardar"}
                            </ReasonButton>
                        </div>
                    </div>
                ) : m.deleted ? (
                    <div>
                        <div className="fst-italic text-muted fs-13">Mensagem apagada pela equipa XPLENDOR, {fmtDateTime(m.deleted_at)}</div>
                        {m.body && <div className="text-muted text-decoration-line-through fs-13 mt-1" style={{ whiteSpace: "pre-wrap" }}>{m.body}</div>}
                    </div>
                ) : (
                    <div style={{ whiteSpace: "pre-wrap" }}>{m.body}</div>
                )}
                <div className={"fs-11 mt-1 " + (m.is_staff && !m.deleted ? "text-white-50" : "text-muted")}>
                    {fmtDateTime(m.created_at)}
                    {m.edited_at && !m.deleted && <> · editada {fmtDateTime(m.edited_at)}</>}
                    {versions.length > 0 && (
                        <button type="button" className={"btn btn-link btn-sm p-0 ms-2 fs-11 " + (m.is_staff && !m.deleted ? "text-white" : "")} onClick={() => setShowVersions((v) => !v)}>
                            {showVersions ? "Esconder versões" : `Ver versões anteriores (${versions.length})`}
                        </button>
                    )}
                </div>
                {showVersions && (
                    <ul className="list-unstyled mb-0 mt-2 vstack gap-2" data-testid={`message-versions-${m.id}`}>
                        {versions.map((v, i) => (
                            <li key={i} className={"p-2 rounded fs-12 " + (m.is_staff && !m.deleted ? "bg-white bg-opacity-10" : "bg-white")}>
                                <div style={{ whiteSpace: "pre-wrap" }}>{v.body}</div>
                                <div className="opacity-75 mt-1">Antes da edição de {fmtDateTime(v.created_at)}{v.edited_by_name ? ` · ${v.edited_by_name}` : ""}</div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

export default AdminTicketDetail;
