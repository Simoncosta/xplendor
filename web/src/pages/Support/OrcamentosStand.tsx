import { useCallback, useEffect, useMemo, useState } from "react";
import { Card, CardBody, Col, Container, Row, Badge, Spinner, Modal, ModalHeader, ModalBody, ModalFooter, Alert } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import ReasonButton from "Components/Common/ReasonButton";
import { getCompanyTicketQuotes, approveCompanyTicketQuotes } from "helpers/laravel_helper";
import { ISupportTicket, ITicketQuotePipeline, QUOTE_STATUS_META, formatEuro } from "common/models/supportTicket.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

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

    const companyId = useWorkingCompanyId();

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

    // Colunas comuns às três listas; a de seleção só existe em "Por aprovar".
    const baseCols: DTColumn<ISupportTicket>[] = [
        { id: "title", header: "Pedido", value: (t) => t.title, hideable: false, mobile: "title", cell: (t) => <span className="fw-medium">{t.title}</span> },
        { id: "amount", header: "Valor", value: (t) => (t.quoted_amount != null ? num(t.quoted_amount) : undefined), cell: (t) => (t.quoted_amount != null ? formatEuro(t.quoted_amount) : "—"), align: "end", nowrap: true },
        { id: "hours", header: "Horas", value: (t) => (t.estimated_hours != null ? num(t.estimated_hours) : undefined), cell: (t) => (t.estimated_hours != null ? `${t.estimated_hours}h` : "—"), align: "end" },
        { id: "author", header: "Pedido por", value: (t) => t.author_name ?? "", cell: (t) => t.author_name || "—" },
        {
            id: "status", header: "Estado", value: (t) => (t.quote_status ? QUOTE_STATUS_META[t.quote_status].label : ""),
            cell: (t) => { const qm = t.quote_status ? QUOTE_STATUS_META[t.quote_status] : null; return qm ? <Badge color={qm.color}>{qm.label}</Badge> : null; },
        },
    ];
    const selectCol: DTColumn<ISupportTicket> = {
        id: "select", header: "Selecionar", hideable: false, mobile: "hide", sortable: false, className: "text-center", cellClassName: () => "text-center",
        cell: (t) => <input type="checkbox" className="form-check-input mt-0" aria-label={`Selecionar: ${t.title}`} checked={selected.has(t.id)} onChange={() => toggle(t.id)} />,
    };
    const pendingCols = useDataColumns<ISupportTicket>("equipa.orcamentos.por-aprovar", [selectCol, ...baseCols]);
    const doingCols = useDataColumns<ISupportTicket>("equipa.orcamentos.em-curso", baseCols);
    const otherCols = useDataColumns<ISupportTicket>("equipa.orcamentos.outros", baseCols);

    // No telemóvel não há coluna de seleção: a caixa vai no próprio cartão da linha.
    const pendingCard = (t: ISupportTicket) => (
        <div className="xp-dt-card d-flex align-items-center gap-3" data-testid="dt-row">
            <input type="checkbox" className="form-check-input flex-shrink-0 mt-0" aria-label={`Selecionar: ${t.title}`} checked={selected.has(t.id)} onChange={() => toggle(t.id)} />
            <div className="flex-grow-1" style={{ minWidth: 0 }}>
                <div className="fw-medium text-truncate">{t.title}</div>
                <small className="text-muted">
                    {t.quoted_amount != null ? formatEuro(t.quoted_amount) : "—"}
                    {t.estimated_hours != null ? ` · ${t.estimated_hours}h` : ""}
                    {t.author_name ? ` · ${t.author_name}` : ""}
                </small>
            </div>
        </div>
    );

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Orçamentos" breadcrumbs={[{ label: "Equipa" }]}
                    info="As alterações ao site orçadas. Selecione as que quer avançar e aprove o pacote." />

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

                {!loading && tickets.length === 0 ? (
                    <PageCard title="Orçamentos" flush={false}>
                        <p className="text-muted mb-0">Ainda não há orçamentos. Quando pedir uma alteração ao site e a XPLENDOR a orçar, aparece aqui.</p>
                    </PageCard>
                ) : (
                    <>
                        {/* Ações em massa no cabeçalho do cartão (já não há barra fixa no fundo). */}
                        <PageCard
                            title={<>Por aprovar {porAprovar.length > 0 && <Badge color="warning" className="ms-1">{porAprovar.length}</Badge>}</>}
                            loading={loading && tickets.length > 0}
                            status={sel.length > 0
                                ? <><span className="fw-semibold text-body">{sel.length} selecionado{sel.length === 1 ? "" : "s"}</span> · Total: <strong className="text-body">{formatEuro(totalAmount)}</strong> · {totalHours}h (sem IVA)</>
                                : porAprovar.length > 0 ? "Selecione os orçamentos que quer aprovar." : undefined}
                            actions={porAprovar.length > 0 ? <>
                                {pendingCols.selector}
                                <button type="button" className="btn btn-sm btn-outline-primary" onClick={toggleAll}>{allSelected ? "Desmarcar todos" : "Selecionar todos"}</button>
                                <ReasonButton size="sm" color="success" onClick={() => setConfirmOpen(true)} reason={sel.length === 0 ? "Selecione pelo menos um orçamento." : null}>
                                    <i className="ri-check-double-line me-1" />Aprovar selecionados
                                </ReasonButton>
                            </> : undefined}
                        >
                            <DataTable
                                columns={pendingCols}
                                data={porAprovar}
                                rowKey={(t) => t.id}
                                loading={loading}
                                caption="Orçamentos por aprovar"
                                empty={{ message: "Nada por aprovar." }}
                                mobileCard={(t) => pendingCard(t)}
                            />
                        </PageCard>

                        {emCurso.length > 0 && (
                            <PageCard title="Aprovados / em curso" actions={doingCols.selector}>
                                <DataTable columns={doingCols} data={emCurso} rowKey={(t) => t.id} caption="Orçamentos aprovados ou em curso" />
                            </PageCard>
                        )}
                        {outros.length > 0 && (
                            <PageCard title="Outros" className="mb-5" actions={otherCols.selector}>
                                <DataTable columns={otherCols} data={outros} rowKey={(t) => t.id} caption="Outros orçamentos" />
                            </PageCard>
                        )}
                    </>
                )}

                {/* Confirmação (ação com consequência). */}
                <Modal isOpen={confirmOpen} toggle={() => !approving && setConfirmOpen(false)} centered>
                    <ModalHeader toggle={() => !approving && setConfirmOpen(false)}>Confirmar aprovação</ModalHeader>
                    <ModalBody>
                        <Alert color="info" className="mb-0">
                            Vai aprovar <strong>{sel.length}</strong> orçamento(s), no total de <strong>{formatEuro(totalAmount)}</strong> ({totalHours}h, sem IVA).
                            A XPLENDOR avança com estes trabalhos. Confirma?
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
