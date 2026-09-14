import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
    Card, CardBody, Col, Container, Row, Badge, Spinner, Input, Label,
    Modal, ModalHeader, ModalBody, ModalFooter,
} from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import {
    getAdminQuotes, getAdminQuotesSummary, createAdminQuote, updateAdminQuote,
    updateAdminQuoteStatus, deleteAdminQuote,
} from "helpers/laravel_helper";
import {
    IQuote, IQuoteSummary, QuoteStatus, QUOTE_STATUS_META, QUOTE_STATUSES, formatQuoteEuro,
} from "common/models/quote.model";

type FormState = {
    client_name: string;
    client_contact: string;
    description: string;
    amount: string;
    notes: string;
};

const EMPTY_FORM: FormState = { client_name: "", client_contact: "", description: "", amount: "", notes: "" };

const fmtDate = (iso?: string) => (iso ? new Date(iso).toLocaleDateString("pt-PT", { day: "numeric", month: "short", year: "numeric" }) : "—");

/**
 * XPLENDOR — Consola de ORÇAMENTOS AVULSOS (gestão comercial, área /admin,
 * só root). Segunda consola da área /admin, ao lado dos tickets. Base do
 * futuro mini-CRM comercial (criar + lista + estados hoje).
 */
const AdminQuotesList = () => {
    document.title = "Administração — Orçamentos | Xplendor";

    const [quotes, setQuotes] = useState<IQuote[]>([]);
    const [summary, setSummary] = useState<IQuoteSummary | null>(null);
    const [loading, setLoading] = useState(true);
    const [fStatus, setFStatus] = useState("");

    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<IQuote | null>(null);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const [saving, setSaving] = useState(false);
    const [busyId, setBusyId] = useState<number | null>(null);

    const loadSummary = useCallback(() => {
        getAdminQuotesSummary().then((r: any) => setSummary(r?.data ?? null)).catch(() => setSummary(null));
    }, []);

    const loadQuotes = useCallback(() => {
        setLoading(true);
        const params: { status?: string } = {};
        if (fStatus) params.status = fStatus;
        getAdminQuotes(params)
            .then((r: any) => setQuotes(r?.data ?? []))
            .catch(() => setQuotes([]))
            .finally(() => setLoading(false));
    }, [fStatus]);

    useEffect(() => { loadQuotes(); }, [loadQuotes]);
    useEffect(() => { loadSummary(); }, [loadSummary]);

    const cards = useMemo(() => ([
        { label: "Em validação", value: summary?.pending ?? 0, color: "warning", icon: "ri-hourglass-line" },
        { label: "Aprovados", value: summary?.approved ?? 0, color: "success", icon: "ri-checkbox-circle-line" },
        { label: "Rejeitados", value: summary?.rejected ?? 0, color: "danger", icon: "ri-close-circle-line" },
        { label: "Valor ganho", value: formatQuoteEuro(summary?.approved_value ?? 0), color: "primary", icon: "ri-money-euro-circle-line" },
    ]), [summary]);

    const openCreate = () => { setEditing(null); setForm(EMPTY_FORM); setModalOpen(true); };
    const openEdit = (q: IQuote) => {
        setEditing(q);
        setForm({
            client_name: q.client_name,
            client_contact: q.client_contact ?? "",
            description: q.description,
            amount: String(q.amount),
            notes: q.notes ?? "",
        });
        setModalOpen(true);
    };

    const save = async () => {
        if (!form.client_name.trim()) { toast.error("Indica o nome do cliente."); return; }
        if (!form.description.trim()) { toast.error("Descreve o orçamento."); return; }
        const amount = Number(form.amount.replace(",", "."));
        if (!amount || amount < 0) { toast.error("Indica um valor válido."); return; }

        const payload = {
            client_name: form.client_name.trim(),
            client_contact: form.client_contact.trim() || null,
            description: form.description.trim(),
            amount,
            notes: form.notes.trim() || null,
        };
        setSaving(true);
        try {
            if (editing) {
                await updateAdminQuote(editing.id, payload);
                toast.success("Orçamento atualizado.");
            } else {
                await createAdminQuote(payload);
                toast.success("Orçamento criado (em validação).");
            }
            setModalOpen(false);
            loadQuotes(); loadSummary();
        } catch (err: any) {
            toast.error(err?.response?.data?.message || "Não foi possível guardar.");
        } finally {
            setSaving(false);
        }
    };

    const changeStatus = async (q: IQuote, status: QuoteStatus) => {
        setBusyId(q.id);
        try {
            await updateAdminQuoteStatus(q.id, status);
            loadQuotes(); loadSummary();
        } catch {
            toast.error("Não foi possível mudar o estado.");
        } finally {
            setBusyId(null);
        }
    };

    const remove = async (q: IQuote) => {
        if (!window.confirm(`Eliminar o orçamento de "${q.client_name}"?`)) return;
        setBusyId(q.id);
        try {
            await deleteAdminQuote(q.id);
            loadQuotes(); loadSummary();
        } catch {
            toast.error("Não foi possível eliminar.");
        } finally {
            setBusyId(null);
        }
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row className="mb-3 align-items-center">
                    <Col>
                        <h4 className="mb-1"><i className="ri-file-list-3-line text-primary me-2" />Administração — Orçamentos</h4>
                        <p className="text-muted mb-0">Gestão comercial da agência. Orçamentos avulsos, incluindo clientes fora da plataforma.</p>
                    </Col>
                    <Col xs="auto">
                        <button type="button" className="btn btn-primary" onClick={openCreate}>
                            <i className="ri-add-line me-1" />Novo orçamento
                        </button>
                    </Col>
                </Row>

                {/* Cartões do topo */}
                <Row className="g-3 mb-3">
                    {cards.map((c) => (
                        <Col key={c.label} xs={6} lg={3}>
                            <Card className="mb-0">
                                <CardBody className="d-flex align-items-center gap-3">
                                    <span className="avatar-sm flex-shrink-0">
                                        <span className={`avatar-title bg-${c.color}-subtle text-${c.color} rounded fs-20`}><i className={c.icon} /></span>
                                    </span>
                                    <div className="min-w-0">
                                        <div className="fs-18 fw-semibold text-truncate">{c.value}</div>
                                        <small className="text-muted">{c.label}</small>
                                    </div>
                                </CardBody>
                            </Card>
                        </Col>
                    ))}
                </Row>

                <Card>
                    <CardBody>
                        <Row className="g-2 mb-3">
                            <Col md={4}>
                                <Input type="select" value={fStatus} onChange={(e) => setFStatus(e.target.value)}>
                                    <option value="">Todos os estados</option>
                                    {QUOTE_STATUSES.map((s) => <option key={s} value={s}>{QUOTE_STATUS_META[s].label}</option>)}
                                </Input>
                            </Col>
                        </Row>

                        {loading ? (
                            <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                        ) : quotes.length === 0 ? (
                            <p className="text-muted mb-0">Sem orçamentos para os filtros escolhidos. Cria o primeiro em "Novo orçamento".</p>
                        ) : (
                            <div className="d-flex flex-column gap-2">
                                {quotes.map((q) => {
                                    const sm = QUOTE_STATUS_META[q.status];
                                    const busy = busyId === q.id;
                                    return (
                                        <div key={q.id} className="border rounded p-3">
                                            <div className="d-flex align-items-start gap-3 flex-wrap">
                                                <div className="flex-grow-1 min-w-0">
                                                    <div className="d-flex align-items-center gap-2 flex-wrap">
                                                        <span className="fw-semibold">{q.client_name}</span>
                                                        <Badge color={sm.color}>{sm.label}</Badge>
                                                    </div>
                                                    <div className="text-muted fs-13 text-truncate">{q.description}</div>
                                                    <small className="text-muted">
                                                        {q.client_contact ? `${q.client_contact} · ` : ""}{fmtDate(q.created_at)}
                                                    </small>
                                                </div>
                                                <div className="text-end flex-shrink-0">
                                                    <div className="fs-16 fw-semibold">{formatQuoteEuro(q.amount)}</div>
                                                </div>
                                            </div>

                                            <div className="d-flex flex-wrap gap-2 mt-2">
                                                {q.status !== "approved" && (
                                                    <button type="button" className="btn btn-soft-success btn-sm" disabled={busy} onClick={() => changeStatus(q, "approved")}>
                                                        {busy ? <Spinner size="sm" /> : <><i className="ri-check-line me-1" />Aprovar</>}
                                                    </button>
                                                )}
                                                {q.status !== "rejected" && (
                                                    <button type="button" className="btn btn-soft-danger btn-sm" disabled={busy} onClick={() => changeStatus(q, "rejected")}>
                                                        <i className="ri-close-line me-1" />Rejeitar
                                                    </button>
                                                )}
                                                {q.status !== "pending" && (
                                                    <button type="button" className="btn btn-soft-warning btn-sm" disabled={busy} onClick={() => changeStatus(q, "pending")}>
                                                        <i className="ri-arrow-go-back-line me-1" />Repor em validação
                                                    </button>
                                                )}
                                                <button type="button" className="btn btn-soft-secondary btn-sm" onClick={() => openEdit(q)}>
                                                    <i className="ri-pencil-line me-1" />Editar
                                                </button>
                                                <button type="button" className="btn btn-soft-secondary btn-sm" disabled={busy} onClick={() => remove(q)}>
                                                    <i className="ri-delete-bin-line" />
                                                </button>
                                            </div>

                                            {q.notes && <div className="text-muted fs-12 mt-2 fst-italic">{q.notes}</div>}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardBody>
                </Card>
            </Container>

            {/* Modal criar / editar */}
            <Modal isOpen={modalOpen} toggle={() => setModalOpen(false)} centered scrollable>
                <ModalHeader toggle={() => setModalOpen(false)}>{editing ? "Editar orçamento" : "Novo orçamento"}</ModalHeader>
                <ModalBody>
                    <div className="mb-3">
                        <Label className="form-label">Cliente</Label>
                        <Input type="text" value={form.client_name} onChange={(e) => setForm({ ...form, client_name: e.target.value })} placeholder="ex.: Spacedrive" />
                    </div>
                    <div className="mb-3">
                        <Label className="form-label">Contacto</Label>
                        <Input type="text" value={form.client_contact} onChange={(e) => setForm({ ...form, client_contact: e.target.value })} placeholder="email ou telefone (opcional)" />
                    </div>
                    <div className="mb-3">
                        <Label className="form-label">Descrição</Label>
                        <Input type="textarea" rows={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="ex.: Tráfego pago 3 meses" />
                    </div>
                    <div className="mb-3">
                        <Label className="form-label">Valor (€)</Label>
                        <Input type="number" min={0} step="0.01" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} placeholder="ex.: 1500" />
                    </div>
                    <div className="mb-1">
                        <Label className="form-label">Notas</Label>
                        <Input type="textarea" rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} placeholder="Observações (opcional)" />
                    </div>
                    {!editing && <small className="text-muted">O orçamento começa em <strong>Em validação</strong>. Marcas aprovado/rejeitado quando o cliente responder.</small>}
                </ModalBody>
                <ModalFooter>
                    <button type="button" className="btn btn-light" onClick={() => setModalOpen(false)}>Cancelar</button>
                    <button type="button" className="btn btn-primary" onClick={save} disabled={saving}>
                        {saving ? <><Spinner size="sm" className="me-1" /> A guardar…</> : (editing ? "Guardar" : "Criar orçamento")}
                    </button>
                </ModalFooter>
            </Modal>
        </div>
    );
};

export default AdminQuotesList;
