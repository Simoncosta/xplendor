import React, { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Badge, Spinner, Input, Modal, ModalHeader, ModalBody, ModalFooter } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import { getAdminTickets, getAdminTicketsSummary, updateAdminTicketStatus, reclassifyAdminTicketType, getAdminTicketsQuotePipeline } from "helpers/laravel_helper";
import {
    ISupportTicket, SupportTicketStatus, SupportTicketType, QuoteStatus,
    TICKET_TYPE_META, TICKET_STATUS_META, QUOTE_STATUS_META, formatEuro, ITicketQuotePipeline,
} from "common/models/supportTicket.model";
import AdminTicketsKanban from "./AdminTicketsKanban";

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
const TYPE_OPTIONS: SupportTicketType[] = ["idea", "improvement", "bug", "suggestion", "site_change"];

/**
 * DMS — Consola de administração: tickets de suporte de TODAS as empresas.
 * Primeira consola real da área /admin. Os cartões do topo são o embrião do
 * dashboard admin; futuras consolas (empresas, utilizadores, métricas) entram
 * como páginas irmãs em /admin, mesmo portão (RequireSuperAdmin + EnsureSuperAdmin).
 */
const AdminTicketsList = () => {
    document.title = "Administração — Suporte | Xplendor";

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

    // Reclassifica o tipo. Atualiza a lista com o ticket devolvido (traz o
    // quote_status já ativado/desativado). Erro (ex.: tirar site_change com
    // orçamento) → toast com a mensagem do servidor.
    const changeTicketType = async (id: number, type: SupportTicketType) => {
        try {
            const r: any = await reclassifyAdminTicketType(id, type);
            const updated = r?.data;
            if (updated) setTickets((prev) => prev.map((t) => (t.id === id ? { ...t, ...updated } : t)));
            toast.success("Tipo do ticket atualizado.");
        } catch (e: any) {
            toast.error(e?.response?.data?.message || "Não foi possível reclassificar o ticket.");
        }
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
    }, [fCompany]);

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

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row className="mb-3 align-items-center">
                    <Col>
                        <h4 className="mb-1"><i className="ri-shield-star-line text-primary me-2" />Administração — Suporte</h4>
                        <p className="text-muted mb-0">Tickets de todas as empresas. Por tratar primeiro.</p>
                    </Col>
                    <Col xs="auto">
                        {/* Toggle Lista / Kanban */}
                        <div className="btn-group" role="group" aria-label="Vista">
                            <button
                                type="button"
                                className={"btn btn-sm " + (view === "list" ? "btn-primary" : "btn-outline-primary")}
                                onClick={() => setView("list")}
                            >
                                <i className="ri-list-check me-1" />Lista
                            </button>
                            <button
                                type="button"
                                className={"btn btn-sm " + (view === "kanban" ? "btn-primary" : "btn-outline-primary")}
                                onClick={() => setView("kanban")}
                            >
                                <i className="ri-layout-grid-line me-1" />Kanban
                            </button>
                        </div>
                    </Col>
                </Row>

                {/* Cartões do topo — base do futuro dashboard admin. */}
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

                {/* Pipeline de ORÇAMENTOS (site_change) — "quanto tenho em cima da mesa"
                    por estado. Segue o filtro de empresa. Valor SEM IVA. */}
                {pipeline && pipeline.total.count > 0 && (
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

                <Card>
                    <CardBody>
                        {/* Filtros. O de ESTADO só faz sentido na Lista — no Kanban
                            as colunas SÃO os estados, por isso é omitido lá. */}
                        <Row className="g-2 mb-3">
                            {view === "list" && (
                                <Col md={4}>
                                    <Input type="select" value={fStatus} onChange={(e) => setFStatus(e.target.value)}>
                                        <option value="">Todos os estados</option>
                                        {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{TICKET_STATUS_META[s].label}</option>)}
                                    </Input>
                                </Col>
                            )}
                            <Col md={4}>
                                <Input type="select" value={fCompany} onChange={(e) => setFCompany(e.target.value)}>
                                    <option value="">Todas as empresas</option>
                                    {companyOptions.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </Input>
                            </Col>
                            <Col md={4}>
                                <Input type="select" value={fType} onChange={(e) => setFType(e.target.value)}>
                                    <option value="">Todos os tipos</option>
                                    {TYPE_OPTIONS.map((t) => <option key={t} value={t}>{TICKET_TYPE_META[t].label}</option>)}
                                </Input>
                            </Col>
                        </Row>

                        {loading ? (
                            <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                        ) : view === "kanban" ? (
                            <AdminTicketsKanban
                                tickets={tickets}
                                detailHref={(id: number) => `/admin/tickets/${id}`}
                                onStatusChange={changeTicketStatus}
                                onTypeChange={changeTicketType}
                            />
                        ) : tickets.length === 0 ? (
                            <p className="text-muted mb-0">Sem tickets para os filtros escolhidos.</p>
                        ) : (
                            <div className="d-flex flex-column gap-2">
                                {tickets.map((t) => {
                                    const tm = TICKET_TYPE_META[t.type];
                                    const isPaid = t.type === "site_change";
                                    const selectable = isPaid && t.quoted_amount != null;
                                    const qm = isPaid && t.quote_status ? QUOTE_STATUS_META[t.quote_status] : null;
                                    const sm = TICKET_STATUS_META[t.status];
                                    return (
                                        <div key={t.id} className="d-flex align-items-center gap-2">
                                        {/* Checkbox só para orçamentos (site_change com valor). */}
                                        <input type="checkbox" className="form-check-input flex-shrink-0 mt-0"
                                            style={{ visibility: selectable ? "visible" : "hidden" }}
                                            checked={selected.has(t.id)} onChange={() => toggleSel(t.id)}
                                            aria-label="Selecionar orçamento" />
                                        <Link to={`/admin/tickets/${t.id}`} className="flex-grow-1 d-flex align-items-center gap-3 border rounded p-3 text-reset text-decoration-none" style={{ minWidth: 0 }}>
                                            <span className="avatar-xs flex-shrink-0"><span className={"avatar-title rounded fs-18 " + (isPaid ? "bg-warning-subtle text-warning" : "bg-light text-primary")}><i className={tm.icon} /></span></span>
                                            <div className="flex-grow-1 min-w-0">
                                                <div className="fw-medium text-truncate">
                                                    {t.title}
                                                    {isPaid && <span className="badge bg-warning-subtle text-warning ms-2"><i className="ri-money-euro-circle-line me-1" />Pago{t.quoted_amount != null ? ` · ${formatEuro(t.quoted_amount)}` : ""}</span>}
                                                </div>
                                                <small className="text-muted">
                                                    <span className="fw-semibold">{t.company_name ?? `Empresa #${t.company_id}`}</span>
                                                    {" · "}{tm.label}{t.author_name ? ` · ${t.author_name}` : ""}
                                                    {t.messages_count ? ` · ${t.messages_count} msg` : ""}
                                                </small>
                                            </div>
                                            {qm
                                                ? <Badge color={qm.color} className="flex-shrink-0">{qm.label}</Badge>
                                                : <Badge color={sm.color} className="flex-shrink-0">{sm.label}</Badge>}
                                            <i className="ri-arrow-right-s-line fs-18 text-muted flex-shrink-0" />
                                        </Link>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardBody>
                </Card>

                {/* Barra de total dos selecionados (aparece na Lista quando há seleção). */}
                {view === "list" && selectedTickets.length > 0 && (
                    <div className="position-fixed bottom-0 start-0 end-0 p-3" style={{ zIndex: 1030, pointerEvents: "none" }}>
                        <Card className="mb-0 shadow mx-auto" style={{ maxWidth: 780, pointerEvents: "auto", border: "1px solid var(--vz-border-color)" }}>
                            <CardBody className="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                                <div>
                                    <span className="fw-semibold">Selecionados: {selectedTickets.length}</span>
                                    <span className="text-muted"> · Total: <strong className="text-body">{formatEuro(selTotalAmount)}</strong> · {selTotalHours}h (sem IVA)</span>
                                </div>
                                <div className="d-flex gap-2">
                                    <button className="btn btn-soft-secondary btn-sm" onClick={() => setSelected(new Set())}>Limpar</button>
                                    <button className="btn btn-primary btn-sm" onClick={() => setResumoOpen(true)}><i className="ri-file-list-3-line me-1" />Gerar resumo</button>
                                </div>
                            </CardBody>
                        </Card>
                    </div>
                )}

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
                                            <td className="text-end">{t.estimated_hours ?? "—"}</td>
                                            <td className="text-end">{t.quoted_amount != null ? formatEuro(t.quoted_amount) : "—"}</td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot><tr className="fw-semibold"><td colSpan={2}>Total ({selectedTickets.length}) — sem IVA</td><td className="text-end">{selTotalHours}h</td><td className="text-end">{formatEuro(selTotalAmount)}</td></tr></tfoot>
                            </table>
                        </div>
                    </ModalBody>
                    <ModalFooter>
                        <button className="btn btn-light" onClick={() => setResumoOpen(false)}>Fechar</button>
                        <button className="btn btn-soft-primary" onClick={() => {
                            const lines = selectedTickets.map((t) => `• ${t.title} (${t.company_name ?? "#" + t.company_id}) — ${t.estimated_hours ?? "?"}h — ${t.quoted_amount != null ? formatEuro(t.quoted_amount) : "—"}`);
                            const text = `Orçamentos selecionados (${selectedTickets.length}):\n${lines.join("\n")}\n\nTotal: ${formatEuro(selTotalAmount)} · ${selTotalHours}h (sem IVA)`;
                            navigator.clipboard?.writeText(text).then(() => toast.success("Resumo copiado."), () => toast.info("Copia manualmente o resumo."));
                        }}><i className="ri-clipboard-line me-1" />Copiar</button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
};

export default AdminTicketsList;
