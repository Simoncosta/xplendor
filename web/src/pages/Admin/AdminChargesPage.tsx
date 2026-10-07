import React, { useCallback, useEffect, useState } from "react";
import { Badge, Button, Card, CardBody, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import { adminChargeAction, adminChargeFilePath, createAdminCharge, getAdminCharges, getAdminCompanies } from "helpers/laravel_helper";
import { openFileGet, openPdfGet } from "helpers/download_helper";
import { AdminCharge, CHARGE_STATUS_META, ChargeStatus, dmy, euro } from "common/models/charge.model";
import XSelect from "pages/Editorial/XSelect";
import PageHeader from "Components/Common/PageHeader";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";

/**
 * Cobranças da XPLENDOR (só o root): todas as despesas da XPLENDOR de todas as empresas,
 * filtros por estado e empresa, vencidas em destaque e "Nova cobrança" com a fatura em PDF.
 * A XPLENDOR não emite faturas: carrega a fatura emitida no programa certificado.
 */

type Summary = { open_amount: number; overdue_amount: number; overdue_count: number; indicated_count: number };
type Dialog = { kind: "cancel" | "refuse"; charge: AdminCharge } | null;

/** O helper da API rejeita os 4xx com o corpo da resposta ({ message, errors }). */
const errorOf = (e: any, fallback: string): string => {
    const body = e?.response?.data ?? e;
    const first = body?.errors ? (Object.values(body.errors).flat()[0] as string) : null;
    return first || body?.message || fallback;
};

const Stat = ({ icon, color, label, value }: { icon: string; color: string; label: string; value: React.ReactNode }) => (
    <Card className="mb-0 h-100">
        <CardBody className="d-flex align-items-center gap-3">
            <span className="avatar-sm flex-shrink-0"><span className={`avatar-title bg-${color}-subtle text-${color} rounded fs-20`}><i className={icon} /></span></span>
            <div className="min-w-0"><div className="fs-18 fw-semibold text-truncate">{value}</div><small className="text-muted">{label}</small></div>
        </CardBody>
    </Card>
);

const emptyForm = { company_id: 0, description: "", amount: "", invoice_date: "", due_date: "", invoice: null as File | null };

export default function AdminChargesPage() {
    document.title = "Cobranças | Xplendor";
    const [charges, setCharges] = useState<AdminCharge[]>([]);
    const [summary, setSummary] = useState<Summary | null>(null);
    const [companies, setCompanies] = useState<{ value: number; label: string }[]>([]);
    const [loading, setLoading] = useState(true);
    const [fStatus, setFStatus] = useState<string>("");
    const [fCompany, setFCompany] = useState(0);
    const [fOverdue, setFOverdue] = useState(false);
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState(emptyForm);
    const [dialog, setDialog] = useState<Dialog>(null);
    const [text, setText] = useState("");
    const [busy, setBusy] = useState<number | "new" | null>(null);

    const load = useCallback(() => {
        setLoading(true);
        getAdminCharges({ status: fStatus || undefined, company_id: fCompany || undefined, overdue: fOverdue ? 1 : undefined })
            .then((r: any) => { setCharges(r?.data?.charges ?? []); setSummary(r?.data?.summary ?? null); })
            .catch(() => toast.error("Não foi possível carregar as cobranças."))
            .finally(() => setLoading(false));
    }, [fStatus, fCompany, fOverdue]);
    useEffect(() => { load(); }, [load]);
    useEffect(() => {
        getAdminCompanies().then((r: any) => setCompanies((r?.data?.companies ?? []).map((c: any) => ({ value: c.id, label: c.name })))).catch(() => setCompanies([]));
    }, []);

    const act = async (c: AdminCharge, action: "paid" | "send", ok: string) => {
        setBusy(c.id);
        try { await adminChargeAction(c.id, action); toast.success(ok); load(); } catch (e) { toast.error(errorOf(e, "Não foi possível concluir.")); } finally { setBusy(null); }
    };
    const confirmDialog = async () => {
        if (!dialog) return;
        setBusy(dialog.charge.id);
        try {
            await adminChargeAction(dialog.charge.id, dialog.kind, dialog.kind === "cancel" ? { reason: text } : { note: text });
            toast.success(dialog.kind === "cancel" ? "Cobrança anulada." : "Pagamento recusado. Os lembretes retomam.");
            setDialog(null); setText(""); load();
        } catch (e) { toast.error(errorOf(e, "Não foi possível concluir.")); } finally { setBusy(null); }
    };
    const create = async () => {
        const fd = new FormData();
        fd.append("company_id", String(form.company_id || ""));
        fd.append("description", form.description);
        fd.append("amount", form.amount.replace(",", "."));
        fd.append("due_date", form.due_date);
        if (form.invoice_date) fd.append("invoice_date", form.invoice_date);
        if (form.invoice) fd.append("invoice", form.invoice);
        setBusy("new");
        try {
            const r: any = await createAdminCharge(fd);
            const sent = r?.data?.reminders_enabled;
            toast.success(sent ? "Cobrança criada e enviada por email." : "Cobrança criada (lembretes desligados nesta empresa: nenhum email enviado).");
            setCreating(false); setForm(emptyForm); load();
        } catch (e) { toast.error(errorOf(e, "Não foi possível criar a cobrança.")); } finally { setBusy(null); }
    };
    const copyLink = async (c: AdminCharge) => {
        if (!c.link) return;
        try { await navigator.clipboard.writeText(c.link); toast.success("Link copiado."); } catch { window.prompt("Copie o link:", c.link); }
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Cobranças" breadcrumbs={[{ label: "Administração" }]}
                    description="Faturas da XPLENDOR aos clientes (emitidas no programa certificado; aqui só se carregam e acompanham)."
                    actions={<Button color="primary" onClick={() => setCreating(true)}><i className="ri-add-line me-1" />Nova cobrança</Button>} />

                <Row className="g-3 mb-3">
                    <Col sm={6} xl={4}><Stat icon="ri-time-line" color="warning" label="Em aberto" value={euro(summary?.open_amount ?? 0)} /></Col>
                    <Col sm={6} xl={4}><Stat icon="ri-alarm-warning-line" color="danger" label={`Vencidas (${summary?.overdue_count ?? 0})`} value={euro(summary?.overdue_amount ?? 0)} /></Col>
                    <Col sm={12} xl={4}><Stat icon="ri-checkbox-circle-line" color="info" label="Pagamentos indicados por confirmar" value={summary?.indicated_count ?? 0} /></Col>
                </Row>

                <Card>
                    <CardBody>
                        <Row className="g-2 mb-3 align-items-end">
                            <Col sm={4} lg={3}>
                                <Label for="charges-status" className="mb-1">Estado</Label>
                                <XSelect<string> id="charges-status" value={fStatus} onChange={setFStatus}
                                    options={[{ value: "", label: "Todos" }, ...(Object.keys(CHARGE_STATUS_META) as ChargeStatus[]).map((s) => ({ value: s, label: CHARGE_STATUS_META[s].label }))]} />
                            </Col>
                            <Col sm={5} lg={4}>
                                <Label for="charges-company" className="mb-1">Empresa</Label>
                                <XSelect<number> id="charges-company" value={fCompany} onChange={setFCompany} searchable options={[{ value: 0, label: "Todas" }, ...companies]} />
                            </Col>
                            <Col sm={3} lg="auto">
                                <div className="form-check form-switch mb-2">
                                    <Input type="switch" className="form-check-input" id="charges-overdue" checked={fOverdue} onChange={(e) => setFOverdue(e.target.checked)} />
                                    <Label className="form-check-label" for="charges-overdue">Só vencidas</Label>
                                </div>
                            </Col>
                        </Row>

                        {loading ? <div className="text-center py-4"><Spinner size="sm" /></div> : charges.length === 0 ? (
                            <p className="text-muted text-center py-4 mb-0">Sem cobranças para os filtros escolhidos.</p>
                        ) : (
                            <ul className="list-unstyled mb-0" data-testid="charges-list">
                                {charges.map((c) => (
                                    <li key={c.id} className={`border rounded p-3 mb-2 ${c.overdue ? "border-danger" : ""}`} data-charge={c.id}>
                                        <div className="d-flex flex-wrap align-items-start gap-2">
                                            <div className="me-auto min-w-0">
                                                <div className="fw-semibold">{c.company}</div>
                                                <div className="text-truncate">{c.description}</div>
                                                <div className="text-muted fs-12">
                                                    Vence a <span className={c.overdue ? "text-danger fw-semibold" : ""}>{dmy(c.due_date)}</span>
                                                    {c.overdue && <Badge color="danger" className="ms-2 fw-normal">Vencida</Badge>}
                                                    {" · "}{c.reminders_enabled ? `Lembretes ligados${c.last_reminder_on ? `, último a ${dmy(c.last_reminder_on)}` : ""}` : "Lembretes desligados"}
                                                    {" · "}Aberto {c.open_count} {c.open_count === 1 ? "vez" : "vezes"}
                                                    {c.recipients.length === 0 && <span className="text-danger"> · Sem destinatário</span>}
                                                </div>
                                                {c.status === "payment_indicated" && (
                                                    <div className="fs-12 mt-1 text-info-emphasis">
                                                        <i className="ri-information-line me-1" />Pagamento indicado a {dmy(c.payment_indicated_at)} ({c.payment_indicated_via === "link" ? "pelo link" : "na plataforma"}){c.payment_note ? `: ${c.payment_note}` : ""}
                                                    </div>
                                                )}
                                                {c.status === "cancelled" && c.cancel_reason && <div className="fs-12 mt-1 text-muted">Anulada: {c.cancel_reason}</div>}
                                            </div>
                                            <div className="text-end">
                                                <div className="fs-16 fw-semibold">{euro(c.amount)}</div>
                                                <Badge color={CHARGE_STATUS_META[c.status].color} className="fw-normal">{CHARGE_STATUS_META[c.status].label}</Badge>
                                            </div>
                                        </div>
                                        <div className="d-flex flex-wrap gap-1 mt-2">
                                            <Button size="sm" color="outline-primary" onClick={async () => { const r = await openPdfGet(adminChargeFilePath(c.id, "invoice")); if (!r.ok) toast.error("Não foi possível abrir a fatura."); }}>
                                                <i className="ri-file-pdf-2-line me-1" />Fatura
                                            </Button>
                                            {(c.status === "open" || c.status === "payment_indicated") && (
                                                <>
                                                    <Button size="sm" color="outline-primary" disabled={busy === c.id} onClick={() => act(c, "send", "Email enviado.")}><i className="ri-mail-send-line me-1" />Enviar agora</Button>
                                                    <Button size="sm" color="success" disabled={busy === c.id} onClick={() => act(c, "paid", "Cobrança marcada como paga.")}>
                                                        <i className="ri-check-double-line me-1" />{c.status === "payment_indicated" ? "Confirmar pagamento" : "Marcar como paga"}
                                                    </Button>
                                                </>
                                            )}
                                            <ActionsMenu size="sm" label={`Mais ações: ${c.description}`} disabled={busy === c.id} items={[
                                                { label: "Comprovativo", icon: "ri-attachment-2", hidden: !c.has_proof, onClick: async () => { const r = await openFileGet(adminChargeFilePath(c.id, "proof")); if (!r.ok) toast.error("Não foi possível abrir o comprovativo."); } },
                                                { label: "Copiar link", icon: "ri-link", hidden: !c.link, onClick: () => copyLink(c) },
                                                { label: "Recusar o pagamento indicado", icon: "ri-close-circle-line", hidden: c.status !== "payment_indicated", onClick: () => { setText(""); setDialog({ kind: "refuse", charge: c }); } },
                                                { label: "Anular", icon: "ri-forbid-2-line", danger: true, hidden: !(c.status === "open" || c.status === "payment_indicated"), onClick: () => { setText(""); setDialog({ kind: "cancel", charge: c }); } },
                                            ]} />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardBody>
                </Card>
            </Container>

            <Modal isOpen={creating} toggle={() => busy !== "new" && setCreating(false)} centered>
                <ModalHeader toggle={() => busy !== "new" && setCreating(false)}>Nova cobrança</ModalHeader>
                <ModalBody>
                    <Label for="nc-company" className="mb-1">Empresa</Label>
                    <div className="mb-3"><XSelect<number> id="nc-company" searchable value={form.company_id} onChange={(v) => setForm({ ...form, company_id: v })} options={[{ value: 0, label: "Escolher…" }, ...companies]} /></div>
                    <Label for="nc-description" className="mb-1">Descrição</Label>
                    <Input id="nc-description" className="mb-3" maxLength={255} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="Por exemplo, Gestão de redes sociais, outubro" />
                    <Row className="g-2 mb-3">
                        <Col xs={12} sm={4}><Label for="nc-amount" className="mb-1">Valor (total da fatura)</Label><Input id="nc-amount" inputMode="decimal" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} placeholder="0,00" /></Col>
                        <Col xs={6} sm={4}><Label for="nc-invoice-date" className="mb-1">Data da fatura</Label><Input id="nc-invoice-date" type="date" value={form.invoice_date} onChange={(e) => setForm({ ...form, invoice_date: e.target.value })} /></Col>
                        <Col xs={6} sm={4}><Label for="nc-due" className="mb-1">Vencimento</Label><Input id="nc-due" type="date" value={form.due_date} onChange={(e) => setForm({ ...form, due_date: e.target.value })} /></Col>
                    </Row>
                    <Label for="nc-invoice" className="mb-1">Fatura em PDF</Label>
                    <Input id="nc-invoice" type="file" accept="application/pdf" onChange={(e) => setForm({ ...form, invoice: e.target.files?.[0] ?? null })} />
                    <p className="text-muted fs-12 mt-3 mb-0">Com os lembretes ligados na empresa, o cliente recebe já um email com o link. Desligados, nenhum email é enviado (pode usar "Enviar agora").</p>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" disabled={busy === "new"} onClick={() => setCreating(false)}>Cancelar</Button>
                    <ReasonButton color="primary" disabled={busy === "new"} onClick={create}
                        reason={!form.company_id ? "Escolha a empresa." : !form.description.trim() ? "Escreva a descrição." : !form.amount ? "Indique o valor." : !form.due_date ? "Indique o vencimento." : !form.invoice ? "Carregue a fatura em PDF." : null}>
                        {busy === "new" ? <Spinner size="sm" /> : "Criar cobrança"}
                    </ReasonButton>
                </ModalFooter>
            </Modal>

            <Modal isOpen={dialog !== null} toggle={() => setDialog(null)} centered>
                <ModalHeader toggle={() => setDialog(null)}>{dialog?.kind === "cancel" ? "Anular a cobrança" : "Recusar o pagamento indicado"}</ModalHeader>
                <ModalBody>
                    <p className="mb-2">{dialog?.charge.company}: {dialog?.charge.description} ({dialog ? euro(dialog.charge.amount) : ""})</p>
                    <Label for="charge-text" className="mb-1">{dialog?.kind === "cancel" ? "Motivo da anulação" : "Nota para o cliente"}</Label>
                    <Input id="charge-text" type="textarea" rows={3} maxLength={dialog?.kind === "cancel" ? 500 : 1000} value={text} onChange={(e) => setText(e.target.value)}
                        placeholder={dialog?.kind === "cancel" ? "Por exemplo, fatura emitida por engano." : "Por exemplo, não encontrámos a transferência; confirme a referência."} />
                    {dialog?.kind === "refuse" && <p className="text-muted fs-12 mt-2 mb-0">A cobrança volta a "em aberto" e os lembretes retomam.</p>}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setDialog(null)}>Cancelar</Button>
                    <ReasonButton color={dialog?.kind === "cancel" ? "danger" : "primary"} disabled={busy !== null} onClick={confirmDialog}
                        reason={!text.trim() ? (dialog?.kind === "cancel" ? "Escreva o motivo da anulação." : "Escreva a nota para o cliente.") : null}>
                        {dialog?.kind === "cancel" ? "Anular a cobrança" : "Recusar o pagamento"}
                    </ReasonButton>
                </ModalFooter>
            </Modal>
        </div>
    );
}
