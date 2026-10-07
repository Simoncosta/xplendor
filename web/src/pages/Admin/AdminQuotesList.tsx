import React, { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Badge, Card, CardBody, Col, Container, Input, Row, Spinner } from "reactstrap";
import { ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import { getAdminQuotes, getAdminQuotesSummary } from "helpers/laravel_helper";
import QuoteSelect from "./QuoteSelect";
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

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Orçamentos" breadcrumbs={[{ label: "Administração", to: "/admin" }]}
                    description="Orçamentos de serviços da XPLENDOR. Valores sem IVA."
                    actions={<>
                        <Link to="/admin/service-catalog" className="btn btn-outline-primary"><i className="ri-price-tag-3-line me-1" />Catálogo de serviços</Link>
                        <Link to="/admin/quotes/new" className="btn btn-primary"><i className="ri-add-line me-1" />Novo orçamento</Link>
                    </>} />

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

                <Card>
                    <CardBody>
                        <Row className="g-2 mb-3">
                            <Col md={4}>
                                <QuoteSelect<string> ariaLabel="Estado" value={fStatus} onChange={(v) => setFStatus(v ?? "")}
                                    options={[{ value: "", label: "Todos os estados" }, ...QUOTE_STATUSES.map((s) => ({ value: s, label: QUOTE_STATUS_META[s].label }))]} />
                            </Col>
                            <Col md={8}>
                                <Input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Pesquisar por cliente, título ou número" />
                            </Col>
                        </Row>

                        {loading ? (
                            <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                        ) : quotes.length === 0 ? (
                            <p className="text-muted mb-0">Sem orçamentos para os filtros escolhidos.</p>
                        ) : (
                            <div className="table-responsive">
                                <table className="table table-hover align-middle mb-0">
                                    <thead className="table-light text-muted">
                                        <tr>
                                            <th>Número</th>
                                            <th>Cliente</th>
                                            <th>Estado</th>
                                            <th className="text-end">Mensal</th>
                                            <th className="text-end">Valor único</th>
                                            <th>Válido até</th>
                                            <th>Visto</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {quotes.map((q) => {
                                            const sm = QUOTE_STATUS_META[q.status];
                                            return (
                                                <tr key={q.id} role="button" onClick={() => navigate(`/admin/quotes/${q.id}`)}>
                                                    <td className="text-nowrap">
                                                        <span className="fw-semibold">{q.display_number}</span>
                                                        {q.version > 1 && <small className="text-muted ms-1">v{q.version}</small>}
                                                    </td>
                                                    <td style={{ minWidth: 180 }}>
                                                        <div className="fw-medium">{q.client_name}</div>
                                                        <small className="text-muted">{q.title || q.description}{q.company_name ? ` · ligado a ${q.company_name}` : ""}</small>
                                                    </td>
                                                    <td><Badge color={sm.color}>{sm.label}</Badge></td>
                                                    <td className="text-end text-nowrap">{q.total_monthly > 0 ? `${formatQuoteEuro(q.total_monthly)}/mês` : <span className="text-muted">0,00 €</span>}</td>
                                                    <td className="text-end text-nowrap">{formatQuoteEuro(q.total_one_off)}</td>
                                                    <td className="text-nowrap">{q.valid_until ? longDate(q.valid_until) : <span className="text-muted">Sem data</span>}</td>
                                                    <td className="text-nowrap">
                                                        {(q.open_count ?? 0) > 0 ? (
                                                            <span className="text-success" title={q.last_opened_at ? `Última abertura: ${longDate(q.last_opened_at)}` : undefined}>
                                                                <i className="ri-eye-line me-1" />{q.open_count} {q.open_count === 1 ? "vez" : "vezes"}
                                                            </span>
                                                        ) : q.status === "draft" ? <span className="text-muted">Por enviar</span> : <span className="text-muted"><i className="ri-eye-off-line me-1" />Não visto</span>}
                                                        {q.changes_requested_at && q.status === "sent" && <div><small className="text-warning">Pediu alterações</small></div>}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <p className="text-muted fs-12 mt-3 mb-0"><i className="ri-information-line me-1" />Os totais mensal e de valor único nunca se somam. Acresce IVA à taxa legal em vigor.</p>
                    </CardBody>
                </Card>
            </Container>
        </div>
    );
};

export default AdminQuotesList;
