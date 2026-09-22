import { useCallback, useEffect, useMemo, useState } from "react";
import { Card, CardBody, Col, Container, Row, Badge, Spinner, Modal, ModalHeader, ModalBody, ModalFooter, Alert } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import { getCompanyTicketQuotes, approveCompanyTicketQuotes } from "helpers/laravel_helper";
import { ISupportTicket, ITicketQuotePipeline, QUOTE_STATUS_META, formatEuro } from "common/models/supportTicket.model";

/**
 * XPLENDOR — Orçamentos do STAND (autonomia do cliente). Mostra os orçamentos-em-
 * tickets (site_change) DA PRÓPRIA empresa (tenancy no backend). O cliente
 * seleciona os que estão "por aprovar" (quote_status='quoted'), vê o total ao vivo
 * (Σ valor + Σ horas, SEM IVA) e APROVA o pacote (com confirmação). Distingue
 * claramente o que está por aprovar do que já foi aprovado/em curso/rejeitado.
 */

const num = (n?: number | null) => Number(n ?? 0);

export default function OrcamentosStand() {
    document.title = "Orçamentos | Xplendor";

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

    const [tickets, setTickets] = useState<ISupportTicket[]>([]);
    const [summary, setSummary] = useState<ITicketQuotePipeline | null>(null);
    const [loading, setLoading] = useState(true);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [approving, setApproving] = useState(false);

    const fetchAll = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getCompanyTicketQuotes(companyId);
            setTickets(res?.data?.tickets ?? []);
            setSummary(res?.data?.summary ?? null);
        } catch {
            setTickets([]); setSummary(null);
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => { fetchAll(); }, [fetchAll]);

    // Separar por fase (o cliente percebe o estado de cada um).
    const porAprovar = tickets.filter((t) => t.quote_status === "quoted");           // selecionáveis
    const emCurso = tickets.filter((t) => ["approved", "paid", "completed"].includes(t.quote_status ?? ""));
    const outros = tickets.filter((t) => ["awaiting_quote", "rejected"].includes(t.quote_status ?? ""));

    const toggle = (id: number) => setSelected((prev) => {
        const next = new Set(prev);
        next.has(id) ? next.delete(id) : next.add(id);
        return next;
    });
    const allSelected = porAprovar.length > 0 && porAprovar.every((t) => selected.has(t.id));
    const toggleAll = () => setSelected(allSelected ? new Set() : new Set(porAprovar.map((t) => t.id)));

    // Total ao vivo dos selecionados (SEM IVA).
    const sel = porAprovar.filter((t) => selected.has(t.id));
    const totalAmount = sel.reduce((a, t) => a + num(t.quoted_amount), 0);
    const totalHours = sel.reduce((a, t) => a + num(t.estimated_hours), 0);

    const doApprove = async () => {
        if (sel.length === 0) return;
        setApproving(true);
        try {
            const res: any = await approveCompanyTicketQuotes(companyId, sel.map((t) => t.id));
            const n = res?.data?.approved?.length ?? 0;
            const skipped = res?.data?.skipped ?? [];
            toast.success(`${n} orçamento(s) aprovado(s).${skipped.length ? ` ${skipped.length} ignorado(s).` : ""}`);
            setSelected(new Set());
            setConfirmOpen(false);
            await fetchAll();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível aprovar os orçamentos.");
        } finally {
            setApproving(false);
        }
    };

    // Cards de pipeline (só os estados com significado para o cliente).
    const pcards = useMemo(() => {
        const b = summary?.by_status;
        const mk = (k: keyof NonNullable<ITicketQuotePipeline["by_status"]>, color: string, icon: string) => ({
            label: QUOTE_STATUS_META[k].label, color, icon,
            amount: b?.[k]?.amount ?? 0, count: b?.[k]?.count ?? 0,
        });
        return [
            mk("quoted", "warning", "ri-hourglass-line"),
            mk("approved", "info", "ri-checkbox-circle-line"),
            mk("paid", "primary", "ri-money-euro-circle-line"),
            mk("completed", "success", "ri-flag-line"),
        ];
    }, [summary]);

    const row = (t: ISupportTicket, selectable: boolean) => {
        const qm = t.quote_status ? QUOTE_STATUS_META[t.quote_status] : null;
        return (
            <div key={t.id} className="d-flex align-items-center gap-3 border rounded p-3">
                {selectable && (
                    <input type="checkbox" className="form-check-input flex-shrink-0 mt-0" checked={selected.has(t.id)} onChange={() => toggle(t.id)} />
                )}
                <div className="flex-grow-1" style={{ minWidth: 0 }}>
                    <div className="fw-medium text-truncate">{t.title}</div>
                    <small className="text-muted">
                        {t.quoted_amount != null ? formatEuro(t.quoted_amount) : "—"}
                        {t.estimated_hours != null ? ` · ${t.estimated_hours}h` : ""}
                        {t.author_name ? ` · ${t.author_name}` : ""}
                    </small>
                </div>
                {qm && <Badge color={qm.color} className="flex-shrink-0">{qm.label}</Badge>}
            </div>
        );
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row className="mb-3"><Col>
                    <h4 className="mb-1"><i className="ri-money-euro-circle-line text-primary me-2" />Orçamentos</h4>
                    <p className="text-muted mb-0">As alterações ao site orçadas. Seleciona as que queres avançar e aprova o pacote.</p>
                </Col></Row>

                {/* Pipeline (cards+SUM, reaproveitando o padrão do sistema). */}
                <Row className="g-3 mb-3">
                    {pcards.map((c) => (
                        <Col key={c.label} xs={6} lg={3}>
                            <Card className="mb-0"><CardBody className="d-flex align-items-center gap-3">
                                <span className="avatar-sm flex-shrink-0"><span className={`avatar-title bg-${c.color}-subtle text-${c.color} rounded fs-20`}><i className={c.icon} /></span></span>
                                <div style={{ minWidth: 0 }}>
                                    <div className="fs-20 fw-semibold text-truncate">{formatEuro(c.amount)}</div>
                                    <small className="text-muted">{c.label} · {c.count}</small>
                                </div>
                            </CardBody></Card>
                        </Col>
                    ))}
                </Row>

                {loading ? (
                    <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                ) : tickets.length === 0 ? (
                    <Card><CardBody className="text-muted">Ainda não há orçamentos. Quando pedires uma alteração ao site e a XPLENDOR a orçar, aparece aqui.</CardBody></Card>
                ) : (
                    <>
                        <Card className="mb-3">
                            <CardBody>
                                <div className="d-flex align-items-center justify-content-between mb-2">
                                    <h5 className="mb-0">Por aprovar {porAprovar.length > 0 && <Badge color="warning" className="ms-1">{porAprovar.length}</Badge>}</h5>
                                    {porAprovar.length > 0 && (
                                        <button className="btn btn-sm btn-link p-0" onClick={toggleAll}>{allSelected ? "Desmarcar todos" : "Selecionar todos"}</button>
                                    )}
                                </div>
                                {porAprovar.length === 0 ? (
                                    <p className="text-muted mb-0">Nada por aprovar.</p>
                                ) : (
                                    <div className="d-flex flex-column gap-2">{porAprovar.map((t) => row(t, true))}</div>
                                )}
                            </CardBody>
                        </Card>

                        {emCurso.length > 0 && (
                            <Card className="mb-3"><CardBody>
                                <h5 className="mb-2">Aprovados / em curso</h5>
                                <div className="d-flex flex-column gap-2">{emCurso.map((t) => row(t, false))}</div>
                            </CardBody></Card>
                        )}
                        {outros.length > 0 && (
                            <Card className="mb-5"><CardBody>
                                <h5 className="mb-2">Outros</h5>
                                <div className="d-flex flex-column gap-2">{outros.map((t) => row(t, false))}</div>
                            </CardBody></Card>
                        )}
                    </>
                )}

                {/* Barra fixa de total + aprovar (aparece quando há seleção). */}
                {sel.length > 0 && (
                    <div className="position-fixed bottom-0 start-0 end-0 p-3" style={{ zIndex: 1030, pointerEvents: "none" }}>
                        <Card className="mb-0 shadow mx-auto" style={{ maxWidth: 720, pointerEvents: "auto", border: "1px solid var(--vz-border-color)" }}>
                            <CardBody className="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                                <div>
                                    <span className="fw-semibold">Selecionados: {sel.length}</span>
                                    <span className="text-muted"> · Total: <strong className="text-body">{formatEuro(totalAmount)}</strong> · {totalHours}h (sem IVA)</span>
                                </div>
                                <button className="btn btn-success" onClick={() => setConfirmOpen(true)}>
                                    <i className="ri-check-double-line me-1" />Aprovar selecionados
                                </button>
                            </CardBody>
                        </Card>
                    </div>
                )}

                {/* Confirmação (ação com consequência). */}
                <Modal isOpen={confirmOpen} toggle={() => !approving && setConfirmOpen(false)} centered>
                    <ModalHeader toggle={() => !approving && setConfirmOpen(false)}>Confirmar aprovação</ModalHeader>
                    <ModalBody>
                        <Alert color="info" className="mb-0">
                            Vais aprovar <strong>{sel.length}</strong> orçamento(s), no total de <strong>{formatEuro(totalAmount)}</strong> ({totalHours}h, sem IVA).
                            A XPLENDOR avança com estes trabalhos. Confirmas?
                        </Alert>
                    </ModalBody>
                    <ModalFooter>
                        <button className="btn btn-light" onClick={() => setConfirmOpen(false)} disabled={approving}>Cancelar</button>
                        <button className="btn btn-success" onClick={doApprove} disabled={approving}>
                            {approving ? <><Spinner size="sm" className="me-1" /> A aprovar…</> : "Confirmar e aprovar"}
                        </button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
}
