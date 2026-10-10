import React, { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Badge, Card, CardBody, Col, Container, Row } from "reactstrap";
import { ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import XSelect from "Components/Common/Select";
import { getAdminQuotes, getAdminQuotesSummary } from "helpers/laravel_helper";
import {
    IQuote, IQuoteSummary, QUOTE_STATUSES, QUOTE_STATUS_META, formatQuoteEuro, longDate,
} from "common/models/quote.model";

/**
 * XPLENDOR — Orçamentos de serviços (só a equipa XPLENDOR). Lista com os totais
 * MENSAL e VALOR ÚNICO sempre em colunas separadas (nunca somados), sem IVA.
 * Abrir um orçamento leva ao editor (/admin/quotes/:id).
 */
const Stat = ({ icon, color, label, value, sub }: { icon: string; color: string; label: string; value: React.ReactNode; sub?: React.ReactNode }) => (
    <Card className="mb-0 h-100">
        <CardBody className="d-flex align-items-center gap-3">
            <span className="avatar-sm flex-shrink-0">
                <span className={`avatar-title bg-${color}-subtle text-${color} rounded fs-20`}><i className={icon} /></span>
            </span>
            <div className="min-w-0">
                <div className="fs-18 fw-semibold text-truncate">{value}</div>
                <small className="text-muted d-block">{label}</small>
                {sub && <small className="text-muted d-block text-truncate">{sub}</small>}
            </div>
        </CardBody>
    </Card>
);

const AdminQuotesList = () => {
    document.title = "Orçamentos | Xplendor";
    const navigate = useNavigate();

    const [quotes, setQuotes] = useState<IQuote[]>([]);
    const [summary, setSummary] = useState<IQuoteSummary | null>(null);
    const [loading, setLoading] = useState(true);
    const [fStatus, setFStatus] = useState("");
    const [search, setSearch] = useState("");

    useEffect(() => {
        getAdminQuotesSummary().then((r: any) => setSummary(r?.data ?? null)).catch(() => setSummary(null));
    }, []);

    useEffect(() => {
        let alive = true;
        setLoading(true);
        const t = setTimeout(() => {
            getAdminQuotes({ status: fStatus || undefined, search: search.trim() || undefined })
                .then((r: any) => { if (alive) setQuotes(r?.data ?? []); })
                .catch(() => { if (alive) setQuotes([]); })
                .finally(() => { if (alive) setLoading(false); });
        }, 250);
        return () => { alive = false; clearTimeout(t); };
    }, [fStatus, search]);

    const year = summary?.accepted_year.year ?? new Date().getFullYear();

    const cols = useDataColumns<IQuote>("administracao.orcamentos", [
        {
            id: "number", header: "Número", value: (q) => q.display_number, hideable: false, nowrap: true,
            cell: (q) => <><span className="fw-semibold">{q.display_number}</span>{q.version > 1 && <small className="text-muted ms-1">v{q.version}</small>}</>,
        },
        {
            id: "client", header: "Cliente", value: (q) => q.client_name, mobile: "title",
            cell: (q) => (
                <div style={{ minWidth: 180 }}>
                    <div className="fw-medium">{q.client_name}</div>
                    <small className="text-muted">{q.title || q.description}{q.company_name ? ` · ligado a ${q.company_name}` : ""}</small>
                </div>
            ),
        },
        { id: "status", header: "Estado", value: (q) => QUOTE_STATUS_META[q.status].label, cell: (q) => <Badge color={QUOTE_STATUS_META[q.status].color}>{QUOTE_STATUS_META[q.status].label}</Badge> },
        { id: "monthly", header: "Mensal", value: (q) => q.total_monthly, cell: (q) => (q.total_monthly > 0 ? `${formatQuoteEuro(q.total_monthly)}/mês` : <span className="text-muted">0,00 €</span>), align: "end", nowrap: true },
        { id: "oneoff", header: "Valor único", value: (q) => q.total_one_off, cell: (q) => formatQuoteEuro(q.total_one_off), align: "end", nowrap: true },
        { id: "valid", header: "Válido até", value: (q) => q.valid_until ?? undefined, cell: (q) => (q.valid_until ? longDate(q.valid_until) : <span className="text-muted">Sem data</span>), nowrap: true },
        {
            id: "seen", header: "Visto", value: (q) => q.open_count ?? 0, nowrap: true,
            cell: (q) => (
                <>
                    {(q.open_count ?? 0) > 0 ? (
                        <span className="text-success" title={q.last_opened_at ? `Última abertura: ${longDate(q.last_opened_at)}` : undefined}>
                            <i className="ri-eye-line me-1" />{q.open_count} {q.open_count === 1 ? "vez" : "vezes"}
                        </span>
                    ) : q.status === "draft" ? <span className="text-muted">Por enviar</span> : <span className="text-muted"><i className="ri-eye-off-line me-1" />Não visto</span>}
                    {q.changes_requested_at && q.status === "sent" && <div><small className="text-warning">Pediu alterações</small></div>}
                </>
            ),
        },
    ] as DTColumn<IQuote>[]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Orçamentos" breadcrumbs={[{ label: "Administração", to: "/admin" }]}
                    info="Orçamentos de serviços da XPLENDOR. Valores sem IVA." />

                {summary && (
                    <Row className="g-3 mb-3">
                        <Col xs={12} sm={6} xl={3}>
                            <Stat icon="ri-send-plane-line" color="warning" label="Em aberto (enviados)"
                                value={`${summary.open.count} · ${formatQuoteEuro(summary.open.monthly)}/mês`}
                                sub={`${formatQuoteEuro(summary.open.one_off)} de valor único`} />
                        </Col>
                        <Col xs={12} sm={6} xl={3}>
                            <Stat icon="ri-repeat-line" color="success" label={`Recorrente aceite em ${year}`}
                                value={`${formatQuoteEuro(summary.accepted_year.monthly)}/mês`}
                                sub={`Desde sempre: ${formatQuoteEuro(summary.accepted_all.monthly)}/mês`} />
                        </Col>
                        <Col xs={12} sm={6} xl={3}>
                            <Stat icon="ri-money-euro-circle-line" color="primary" label={`Único aceite em ${year}`}
                                value={formatQuoteEuro(summary.accepted_year.one_off)}
                                sub={`Desde sempre: ${formatQuoteEuro(summary.accepted_all.one_off)}`} />
                        </Col>
                        <Col xs={12} sm={6} xl={3}>
                            <Stat icon="ri-timer-line" color="danger" label="Expiram nos próximos 7 dias"
                                value={summary.open.expiring_7d}
                                sub={`${summary.by_status.draft} em rascunho`} />
                        </Col>
                    </Row>
                )}

                <PageCard
                    title="Orçamentos"
                    info="Os totais mensal e de valor único nunca se somam. Acresce IVA à taxa legal em vigor."
                    status={!loading ? <>{quotes.length} orçamento{quotes.length === 1 ? "" : "s"}</> : undefined}
                    loading={loading && quotes.length > 0}
                    actions={<>
                        {cols.selector}
                        <Link to="/admin/service-catalog" className="btn btn-outline-primary btn-sm"><i className="ri-price-tag-3-line me-1" />Catálogo de serviços</Link>
                        <Link to="/admin/quotes/new" className="btn btn-primary btn-sm"><i className="ri-add-line me-1" />Novo orçamento</Link>
                    </>}
                    filters={
                        <RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Pesquisar por cliente, título ou número"
                            activeCount={(search ? 1 : 0) + (fStatus ? 1 : 0)} onClear={() => { setSearch(""); setFStatus(""); }}>
                            <div style={{ flex: "0 1 220px", minWidth: 0 }}>
                                <XSelect<string> small ariaLabel="Estado" value={fStatus} onChange={(v) => setFStatus(v ?? "")} searchable={false}
                                    options={[{ value: "", label: "Todos os estados" }, ...QUOTE_STATUSES.map((s) => ({ value: s, label: QUOTE_STATUS_META[s].label }))]} />
                            </div>
                        </RestFilterBar>
                    }
                >
                    <DataTable
                        columns={cols}
                        data={quotes}
                        rowKey={(q) => q.id}
                        loading={loading}
                        caption="Orçamentos de serviços"
                        onRowClick={(q) => navigate(`/admin/quotes/${q.id}`)}
                        empty={{ message: "Sem orçamentos para os filtros escolhidos." }}
                        rowActions={(q) => (
                            <Link to={`/admin/quotes/${q.id}`} className="btn btn-outline-primary btn-sm" title="Abrir" aria-label={`Abrir: ${q.display_number}`} onClick={(e) => e.stopPropagation()}>
                                <i className="ri-pencil-line" />
                            </Link>
                        )}
                    />
                </PageCard>
            </Container>
        </div>
    );
};

export default AdminQuotesList;
