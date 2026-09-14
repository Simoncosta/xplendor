import React, { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Badge, Spinner, Input } from "reactstrap";
import { ToastContainer } from "react-toastify";
import { getAdminTickets, getAdminTicketsSummary } from "helpers/laravel_helper";
import {
    ISupportTicket, SupportTicketStatus, SupportTicketType,
    TICKET_TYPE_META, TICKET_STATUS_META, QUOTE_STATUS_META, formatEuro,
} from "common/models/supportTicket.model";

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

    // Opções de empresa derivadas dos tickets carregados (sem endpoint extra).
    const [companyOptions, setCompanyOptions] = useState<{ id: number; name: string }[]>([]);

    useEffect(() => {
        getAdminTicketsSummary().then((r: any) => setSummary(r?.data ?? null)).catch(() => setSummary(null));
    }, []);

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
                <Row className="mb-3">
                    <Col>
                        <h4 className="mb-1"><i className="ri-shield-star-line text-primary me-2" />Administração — Suporte</h4>
                        <p className="text-muted mb-0">Tickets de todas as empresas. Por tratar primeiro.</p>
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

                <Card>
                    <CardBody>
                        {/* Filtros */}
                        <Row className="g-2 mb-3">
                            <Col md={4}>
                                <Input type="select" value={fStatus} onChange={(e) => setFStatus(e.target.value)}>
                                    <option value="">Todos os estados</option>
                                    {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{TICKET_STATUS_META[s].label}</option>)}
                                </Input>
                            </Col>
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
                        ) : tickets.length === 0 ? (
                            <p className="text-muted mb-0">Sem tickets para os filtros escolhidos.</p>
                        ) : (
                            <div className="d-flex flex-column gap-2">
                                {tickets.map((t) => {
                                    const tm = TICKET_TYPE_META[t.type];
                                    const isPaid = t.type === "site_change";
                                    const qm = isPaid && t.quote_status ? QUOTE_STATUS_META[t.quote_status] : null;
                                    const sm = TICKET_STATUS_META[t.status];
                                    return (
                                        <Link key={t.id} to={`/admin/tickets/${t.id}`} className="d-flex align-items-center gap-3 border rounded p-3 text-reset text-decoration-none">
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
                                    );
                                })}
                            </div>
                        )}
                    </CardBody>
                </Card>
            </Container>
        </div>
    );
};

export default AdminTicketsList;
