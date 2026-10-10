import React, { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Badge, Modal, ModalHeader, ModalBody, ModalFooter } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import XSelect from "Components/Common/Select";
import { ToastContainer, toast } from "react-toastify";
import { getAdminTickets, getAdminTicketsSummary, updateAdminTicketStatus, getAdminTicketsQuotePipeline } from "helpers/laravel_helper";
import {
    ISupportTicket, SupportTicketStatus, SupportTicketType, QuoteStatus,
    TICKET_TYPE_META, TICKET_STATUS_META, QUOTE_STATUS_META, formatEuro, ITicketQuotePipeline,
} from "common/models/supportTicket.model";
import AdminTicketsKanban from "./AdminTicketsKanban";
import { useTicketTypeChange } from "Components/Common/useTicketTypeChange";

// Estados de pipeline mostrados como cards (valor "em cima da mesa" por estado).
const PIPELINE_CARDS: { key: QuoteStatus; color: string; icon: string }[] = [
    { key: "awaiting_quote", color: "secondary", icon: "ri-hourglass-line" },
    { key: "quoted", color: "warning", icon: "ri-price-tag-3-line" },
    { key: "approved", color: "info", icon: "ri-checkbox-circle-line" },
    { key: "paid", color: "primary", icon: "ri-money-euro-circle-line" },
    { key: "completed", color: "success", icon: "ri-flag-line" },
];

type ViewMode = "list" | "kanban";


interface Summary { open: number; in_review: number; pending: number; resolved: number; closed: number; total: number; }

const STATUS_OPTIONS: SupportTicketStatus[] = ["open", "in_review", "resolved", "closed"];
// Inclui "Arranque" (tickets criados por orçamentos aceites) para a equipa os poder filtrar.
const TYPE_OPTIONS: SupportTicketType[] = ["idea", "improvement", "bug", "suggestion", "site_change", "onboarding"];

/**
 * DMS — Consola de administração: tickets de suporte de TODAS as empresas.
 * Primeira consola real da área /admin. Os cartões do topo são o embrião do
 * dashboard admin; futuras consolas (empresas, utilizadores, métricas) entram
 * como páginas irmãs em /admin, mesmo portão (RequireSuperAdmin + EnsureSuperAdmin).
 */
const AdminTicketsList = () => {
    document.title = "Administração do suporte | Xplendor";
    const navigate = useNavigate();

    const [summary, setSummary] = useState<Summary | null>(null);
    const [tickets, setTickets] = useState<ISupportTicket[]>([]);
    const [loading, setLoading] = useState(true);

    const [fStatus, setFStatus] = useState("");
    const [fCompany, setFCompany] = useState("");
    const [fType, setFType] = useState("");
    const [view, setView] = useState<ViewMode>("kanban"); // Kanban por defeito (Simon pode mudar para Lista)

    // Muda o estado de um ticket (usado pelo drag do Kanban). Persiste + atualiza
    // a lista local; relança em erro para o Kanban reverter o cartão.
    const changeTicketStatus = async (id: number, status: SupportTicketStatus) => {
        try {
            await updateAdminTicketStatus(id, status);
            setTickets((prev) => prev.map((t) => (t.id === id ? { ...t, status } : t)));
        } catch (e) {
            toast.error("Não foi possível mudar o estado do ticket.");
            throw e;
        }
    };

    // Muda o tipo (menu do cartão no Kanban). Com orçamento orçado ou rejeitado, o hook
    // pede confirmação; aprovado, pago ou concluído chega bloqueado com a razão.
    // Atualiza a lista e o pipeline com o ticket devolvido.
    const [pipelineTick, setPipelineTick] = useState(0);
    const { requestTypeChange, modal: typeChangeModal } = useTicketTypeChange((updated) => {
        setTickets((prev) => prev.map((t) => (t.id === updated.id ? { ...t, ...updated } : t)));
        setPipelineTick((n) => n + 1);
    });
    const changeTicketType = async (id: number, type: SupportTicketType) => {
        const ticket = tickets.find((t) => t.id === id);
        if (ticket) requestTypeChange(ticket, type);
    };

    // Opções de empresa derivadas dos tickets carregados (sem endpoint extra).
    const [companyOptions, setCompanyOptions] = useState<{ id: number; name: string }[]>([]);

    // Pipeline de orçamentos (site_change) + seleção para somar/resumir.
    const [pipeline, setPipeline] = useState<ITicketQuotePipeline | null>(null);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [resumoOpen, setResumoOpen] = useState(false);

    useEffect(() => {
        getAdminTicketsSummary().then((r: any) => setSummary(r?.data ?? null)).catch(() => setSummary(null));
    }, []);

    // Pipeline segue o filtro de empresa (ver "em cima da mesa" por cliente).
    useEffect(() => {
        const params: any = {};
        if (fCompany) params.company_id = Number(fCompany);
        getAdminTicketsQuotePipeline(params).then((r: any) => setPipeline(r?.data ?? null)).catch(() => setPipeline(null));
    }, [fCompany, pipelineTick]);

    // Kanban: no telemóvel o resumo dos orçamentos começa recolhido.
    const [stripOpen, setStripOpen] = useState(false);

    // Ao mudar filtros, limpa a seleção (evita somar linhas que já não se veem).
    useEffect(() => { setSelected(new Set()); }, [fStatus, fCompany, fType, view]);

    const toggleSel = (id: number) => setSelected((prev) => {
        const next = new Set(prev); next.has(id) ? next.delete(id) : next.add(id); return next;
    });

    // Tickets site_change com orçamento visíveis (selecionáveis).
    const selectableTickets = useMemo(
        () => tickets.filter((t) => t.type === "site_change" && t.quoted_amount != null),
        [tickets]
    );
    const selectedTickets = selectableTickets.filter((t) => selected.has(t.id));
    const selTotalAmount = selectedTickets.reduce((a, t) => a + Number(t.quoted_amount ?? 0), 0);
    const selTotalHours = selectedTickets.reduce((a, t) => a + Number(t.estimated_hours ?? 0), 0);

    useEffect(() => {
        let alive = true;
        setLoading(true);
        const params: any = {};
        if (fStatus) params.status = fStatus;
        if (fCompany) params.company_id = Number(fCompany);
        if (fType) params.type = fType;
        getAdminTickets(params)
            .then((r: any) => {
                if (!alive) return;
                const list: ISupportTicket[] = r?.data ?? [];
                setTickets(list);
            })
            .catch(() => { if (alive) setTickets([]); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [fStatus, fCompany, fType]);

    // Popula as opções de empresa a partir de uma leitura sem filtros (1ª vez).
    useEffect(() => {
        getAdminTickets().then((r: any) => {
            const list: ISupportTicket[] = r?.data ?? [];
            const map = new Map<number, string>();
            list.forEach((t) => { if (t.company_name) map.set(t.company_id, t.company_name); });
            setCompanyOptions(Array.from(map.entries()).map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name)));
        }).catch(() => setCompanyOptions([]));
    }, []);

    const cards = useMemo(() => ([
        { label: "Por tratar", value: summary?.pending ?? 0, color: "warning", icon: "ri-inbox-unarchive-line" },
        { label: "Abertos", value: summary?.open ?? 0, color: "primary", icon: "ri-mail-open-line" },
        { label: "Resolvidos", value: summary?.resolved ?? 0, color: "success", icon: "ri-checkbox-circle-line" },
        { label: "Total", value: summary?.total ?? 0, color: "secondary", icon: "ri-stack-line" },
    ]), [summary]);

    const FL = "text-muted fw-semibold fs-11 text-uppercase mb-1";
    const cols = useDataColumns<ISupportTicket>("administracao.tickets", [
        {
            id: "select", header: "Selecionar", hideable: false, sortable: false,
            cell: (t) => (t.type === "site_change" && t.quoted_amount != null)
                ? <input type="checkbox" className="form-check-input mt-0" checked={selected.has(t.id)} onChange={() => toggleSel(t.id)} onClick={(e) => e.stopPropagation()} aria-label={`Selecionar orçamento: ${t.title}`} />
                : null,
        },
        {
            id: "title", header: "Ticket", value: (t) => t.title, hideable: false, mobile: "title",
            cell: (t) => {
                const tm = TICKET_TYPE_META[t.type];
                const isPaid = t.type === "site_change";
                return (
                    <span className="d-inline-flex align-items-center gap-2" style={{ minWidth: 0 }}>
                        <span className="avatar-xs flex-shrink-0"><span className={"avatar-title rounded fs-18 " + (isPaid ? "bg-warning-subtle text-warning" : "bg-light text-primary")}><i className={tm.icon} /></span></span>
                        <span className="fw-medium text-break">
                            {t.title}
                            {isPaid && <span className="badge bg-warning-subtle text-warning ms-2"><i className="ri-money-euro-circle-line me-1" />Pago{t.quoted_amount != null ? ` · ${formatEuro(t.quoted_amount)}` : ""}</span>}
                        </span>
                    </span>
                );
            },
        },
        { id: "company", header: "Empresa", value: (t) => t.company_name ?? `Empresa #${t.company_id}`, mobile: "subtitle", cell: (t) => <span className="fw-semibold">{t.company_name ?? `Empresa #${t.company_id}`}</span> },
        { id: "type", header: "Tipo", value: (t) => TICKET_TYPE_META[t.type].label },
        { id: "author", header: "Autor", value: (t) => t.author_name ?? "", cell: (t) => t.author_name || "—", defaultVisible: false },
        { id: "messages", header: "Mensagens", value: (t) => t.messages_count ?? 0, cell: (t) => t.messages_count || "—", align: "end" },
        {
            id: "status", header: "Estado",
            value: (t) => (t.type === "site_change" && t.quote_status ? QUOTE_STATUS_META[t.quote_status].label : TICKET_STATUS_META[t.status].label),
            cell: (t) => {
                const qm = t.type === "site_change" && t.quote_status ? QUOTE_STATUS_META[t.quote_status] : null;
                const sm = TICKET_STATUS_META[t.status];
                return qm ? <Badge color={qm.color}>{qm.label}</Badge> : <Badge color={sm.color}>{sm.label}</Badge>;
            },
        },
    ] as DTColumn<ISupportTicket>[]);

    const filterCount = [view === "list" && fStatus, fCompany, fType].filter(Boolean).length;
    const hasSelection = view === "list" && selectedTickets.length > 0;

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Tickets" breadcrumbs={[{ label: "Administração" }]}
                    info="Tickets de todas as empresas. Por tratar primeiro." />

                {typeChangeModal}

                {/* Cartões do topo (só na Lista; no Kanban os contadores estão no cabeçalho de cada coluna). */}
                {view === "list" && (
                <Row className="g-3 mb-3">
                    {cards.map((c) => (
                        <Col key={c.label} xs={6} lg={3}>
                            <Card className="mb-0">
                                <CardBody className="d-flex align-items-center gap-3">
                                    <span className="avatar-sm flex-shrink-0">
                                        <span className={`avatar-title bg-${c.color}-subtle text-${c.color} rounded fs-20`}><i className={c.icon} /></span>
                                    </span>
                                    <div>
                                        <div className="fs-22 fw-semibold">{c.value}</div>
                                        <small className="text-muted">{c.label}</small>
                                    </div>
                                </CardBody>
                            </Card>
                        </Col>
                    ))}
                </Row>
                )}

                {/* Kanban: pipeline de orçamentos numa faixa compacta (quebra linha em vez de alargar
                    a página; no telemóvel fica recolhida atrás de "Resumo dos orçamentos"). */}
                {view === "kanban" && pipeline && pipeline.total.count > 0 && (
                    <Card className="mb-3">
                        <CardBody className="py-2 px-3">
                            <button type="button" className="btn btn-link btn-sm p-0 text-reset d-md-none d-flex align-items-center gap-1"
                                onClick={() => setStripOpen((o) => !o)} aria-expanded={stripOpen}>
                                <i className={stripOpen ? "ri-arrow-up-s-line" : "ri-arrow-down-s-line"} />
                                Resumo dos orçamentos · {formatEuro(pipeline.total.amount)}
                            </button>
                            <div className={`${stripOpen ? "d-flex mt-2" : "d-none"} d-md-flex flex-wrap align-items-center column-gap-4 row-gap-1`}>
                                <span className="text-muted fs-12 text-uppercase fw-semibold d-none d-md-inline">Orçamentos</span>
                                {PIPELINE_CARDS.map((c) => {
                                    const b = pipeline.by_status[c.key];
                                    return (
                                        <span key={c.key} className="d-inline-flex flex-wrap align-items-center column-gap-2 fs-13">
                                            <i className={`${c.icon} text-${c.color}`} />
                                            <span className="text-muted">{QUOTE_STATUS_META[c.key].label}</span>
                                            <span className="fw-semibold">{formatEuro(b.amount)}</span>
                                            <span className="text-muted">({b.count} · {b.hours} h)</span>
                                        </span>
                                    );
                                })}
                            </div>
                        </CardBody>
                    </Card>
                )}

                {/* Pipeline de ORÇAMENTOS (site_change), na Lista: "quanto tenho em cima da mesa"
                    por estado. Segue o filtro de empresa. Valor SEM IVA. */}
                {view === "list" && pipeline && pipeline.total.count > 0 && (
                    <Row className="g-2 mb-3">
                        {PIPELINE_CARDS.map((c) => {
                            const b = pipeline.by_status[c.key];
                            return (
                                <Col key={c.key} xs={6} md>
                                    <Card className="mb-0"><CardBody className="py-2 px-3">
                                        <div className="d-flex align-items-center gap-2 mb-1">
                                            <i className={`${c.icon} text-${c.color}`} />
                                            <small className="text-muted text-truncate">{QUOTE_STATUS_META[c.key].label}</small>
                                        </div>
                                        <div className="fs-18 fw-semibold">{formatEuro(b.amount)}</div>
                                        <small className="text-muted">{b.count} · {b.hours}h</small>
                                    </CardBody></Card>
                                </Col>
                            );
                        })}
                    </Row>
                )}

                {/* Ações em massa no cabeçalho do cartão (já não há barra fixa no fundo). */}
                <PageCard
                    title="Tickets"
                    flush={view === "list"}
                    loading={loading && tickets.length > 0}
                    status={hasSelection
                        ? <><span className="fw-semibold text-body">{selectedTickets.length} selecionado{selectedTickets.length === 1 ? "" : "s"}</span> · Total: <strong className="text-body">{formatEuro(selTotalAmount)}</strong> · {selTotalHours}h (sem IVA)</>
                        : !loading ? <>{tickets.length} ticket{tickets.length === 1 ? "" : "s"}</> : undefined}
                    actions={<>
                        <div className="xp-seg" role="tablist" aria-label="Vista">
                            <button type="button" role="tab" aria-selected={view === "list"} className={view === "list" ? "on" : ""} onClick={() => setView("list")}><i className="ri-list-check me-1" />Lista</button>
                            <button type="button" role="tab" aria-selected={view === "kanban"} className={view === "kanban" ? "on" : ""} onClick={() => setView("kanban")}><i className="ri-layout-grid-line me-1" />Kanban</button>
                        </div>
                        {view === "list" && cols.selector}
                        {hasSelection && (
                            <>
                                <button className="btn btn-outline-primary btn-sm" onClick={() => setSelected(new Set())}>Limpar</button>
                                <button className="btn btn-primary btn-sm" onClick={() => setResumoOpen(true)}><i className="ri-file-list-3-line me-1" />Gerar resumo</button>
                            </>
                        )}
                    </>}
                    filters={
                        // O filtro de ESTADO só faz sentido na Lista: no Kanban as colunas SÃO os estados.
                        <RestFilterBar activeCount={filterCount} onClear={() => { setFStatus(""); setFCompany(""); setFType(""); }}>
                            {view === "list" && (
                                <div style={{ flex: "1 1 180px", minWidth: 0 }}>
                                    <div className={FL}>Estado</div>
                                    <XSelect small value={fStatus} onChange={setFStatus} ariaLabel="Filtrar por estado" searchable={false}
                                        options={[{ value: "", label: "Todos os estados" }, ...STATUS_OPTIONS.map((st) => ({ value: st, label: TICKET_STATUS_META[st].label }))]} />
                                </div>
                            )}
                            <div style={{ flex: "1 1 180px", minWidth: 0 }}>
                                <div className={FL}>Empresa</div>
                                <XSelect small value={fCompany} onChange={setFCompany} ariaLabel="Filtrar por empresa" searchable
                                    options={[{ value: "", label: "Todas as empresas" }, ...companyOptions.map((c) => ({ value: String(c.id), label: c.name }))]} />
                            </div>
                            <div style={{ flex: "1 1 180px", minWidth: 0 }}>
                                <div className={FL}>Tipo</div>
                                <XSelect small value={fType} onChange={setFType} ariaLabel="Filtrar por tipo" searchable={false}
                                    options={[{ value: "", label: "Todos os tipos" }, ...TYPE_OPTIONS.map((t) => ({ value: t, label: TICKET_TYPE_META[t].label }))]} />
                            </div>
                        </RestFilterBar>
                    }
                >
                    {view === "kanban" ? (
                        loading
                            ? <div className="text-muted">A carregar…</div>
                            : <AdminTicketsKanban
                                tickets={tickets}
                                detailHref={(id: number) => `/admin/tickets/${id}`}
                                onStatusChange={changeTicketStatus}
                                onTypeChange={changeTicketType}
                            />
                    ) : (
                        <DataTable
                            columns={cols}
                            data={tickets}
                            rowKey={(t) => t.id}
                            loading={loading}
                            caption="Tickets"
                            onRowClick={(t) => navigate(`/admin/tickets/${t.id}`)}
                            empty={{ message: "Sem tickets para os filtros escolhidos." }}
                        />
                    )}
                </PageCard>

                {/* Resumo dos selecionados — para o Simon comunicar à Matilde. */}
                <Modal isOpen={resumoOpen} toggle={() => setResumoOpen(false)} centered size="lg">
                    <ModalHeader toggle={() => setResumoOpen(false)}>Resumo dos orçamentos selecionados</ModalHeader>
                    <ModalBody>
                        <div className="table-responsive">
                            <table className="table table-sm align-middle mb-2">
                                <thead className="text-muted table-light"><tr><th>Orçamento</th><th>Empresa</th><th className="text-end">Horas</th><th className="text-end">Valor</th></tr></thead>
                                <tbody>
                                    {selectedTickets.map((t) => (
                                        <tr key={t.id}>
                                            <td>{t.title}</td>
                                            <td>{t.company_name ?? `#${t.company_id}`}</td>
                                            <td className="text-end">{t.estimated_hours ?? "Sem horas"}</td>
                                            <td className="text-end">{t.quoted_amount != null ? formatEuro(t.quoted_amount) : "Sem valor"}</td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot><tr className="fw-semibold"><td colSpan={2}>Total ({selectedTickets.length}), sem IVA</td><td className="text-end">{selTotalHours}h</td><td className="text-end">{formatEuro(selTotalAmount)}</td></tr></tfoot>
                            </table>
                        </div>
                    </ModalBody>
                    <ModalFooter>
                        <button className="btn btn-light" onClick={() => setResumoOpen(false)}>Fechar</button>
                        <button className="btn btn-primary" onClick={() => {
                            const lines = selectedTickets.map((t) => `• ${t.title} (${t.company_name ?? "#" + t.company_id}): ${t.estimated_hours ?? "?"}h, ${t.quoted_amount != null ? formatEuro(t.quoted_amount) : "sem valor"}`);
                            const text = `Orçamentos selecionados (${selectedTickets.length}):\n${lines.join("\n")}\n\nTotal: ${formatEuro(selTotalAmount)} · ${selTotalHours}h (sem IVA)`;
                            navigator.clipboard?.writeText(text).then(() => toast.success("Resumo copiado."), () => toast.info("Copie o resumo manualmente."));
                        }}><i className="ri-clipboard-line me-1" />Copiar</button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
};

export default AdminTicketsList;
