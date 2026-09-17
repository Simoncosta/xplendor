import React, { useEffect, useMemo } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Spinner } from "reactstrap";
import { getAnalyticsDashboard } from "slices/dashboards/thunk";
import SummaryDashboard from "../Dashboards/components/SummaryDashboard";

/**
 * XPLENDOR — Painel de MONITORIZAÇÃO DE STOCK (lado stand, por empresa).
 *
 * Junta num só sítio as métricas de stock que JÁ existem e viviam dispersas
 * (SummaryDashboard, marketing_performance, top_interest/low_lead). NÃO recalcula
 * nada: consome o mesmo blob `analytics` já produzido por getDashboard e reutiliza
 * o componente SummaryDashboard. As métricas dependentes (favoritos, comparações,
 * QR, demografia) aparecem como "Em breve", com a dependência indicada.
 *
 * Tenancy: usa o company_id do utilizador autenticado — cada empresa vê o SEU
 * stock (NÃO é a vista transversal /admin/stock, que é do root).
 */

interface MarketingPerformance {
    views_last_7_days: number;
    leads_last_7_days: number;
    interactions_last_7_days: number;
    interest_rate: number;
}

interface DemandCar {
    id: number;
    version?: string | null;
    price_gross?: number | null;
    views_count?: number;
    leads_count?: number;
    interactions_count?: number;
    brand?: { name?: string | null } | null;
    model?: { name?: string | null } | null;
}

interface StockAnalytics {
    summary?: React.ComponentProps<typeof SummaryDashboard>["summary"];
    marketing_performance?: MarketingPerformance;
    top_interest_cars?: DemandCar[];
    low_lead_cars?: DemandCar[];
}

const selectVM = createSelector(
    [(state: any) => state.Dashboard],
    (d) => ({ analytics: d.data.analytics as StockAnalytics | null, loading: d.loading.list as boolean }),
);

const nfmt = (n?: number | null) => Number(n ?? 0).toLocaleString("pt-PT");
const carName = (c: DemandCar) =>
    [c.brand?.name, c.model?.name, c.version].filter(Boolean).join(" ") || "Sem nome";
const euro = (v?: number | null) =>
    v == null ? "—" : new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(v);

/** Cartão de métrica dependente — placeholder elegante com a dependência. */
const ComingSoonCard: React.FC<{ icon: string; title: string; depends: string }> = ({ icon, title, depends }) => (
    <Col md={6} xl={3}>
        <Card className="mb-0 h-100" style={{ borderStyle: "dashed" }}>
            <CardBody className="text-center py-4">
                <span className="avatar-sm mx-auto mb-3 d-inline-flex">
                    <span className="avatar-title bg-light text-muted rounded fs-20"><i className={icon} /></span>
                </span>
                <h6 className="mb-1">{title}</h6>
                <span className="badge bg-secondary-subtle text-secondary mb-2">Em breve</span>
                <p className="text-muted fs-12 mb-0">{depends}</p>
            </CardBody>
        </Card>
    </Col>
);

const DemandList: React.FC<{ title: string; hint: string; cars: DemandCar[]; metric: "views" | "lowlead" }> = ({ title, hint, cars, metric }) => (
    <Card className="mb-0 h-100">
        <CardBody>
            <div className="mb-3">
                <h5 className="mb-1">{title}</h5>
                <p className="text-muted fs-13 mb-0">{hint}</p>
            </div>
            {cars.length === 0 ? (
                <p className="text-muted mb-0">Sem dados suficientes ainda.</p>
            ) : (
                <div className="d-flex flex-column gap-2">
                    {cars.map((c) => (
                        <Link key={c.id} to={`/cars/${c.id}/analytics`} className="d-flex align-items-center gap-3 border rounded p-2 px-3 text-reset text-decoration-none">
                            <div className="flex-grow-1 min-w-0">
                                <div className="fw-medium text-truncate">{carName(c)}</div>
                                <small className="text-muted">{euro(c.price_gross)}</small>
                            </div>
                            <div className="text-end flex-shrink-0">
                                <div className="fw-semibold">{nfmt(c.views_count)} <span className="fw-normal text-muted fs-12">views</span></div>
                                <small className={metric === "lowlead" ? "text-danger" : "text-muted"}>
                                    {nfmt(c.leads_count)} leads · {nfmt(c.interactions_count)} interações
                                </small>
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </CardBody>
    </Card>
);

const StockMonitoring = () => {
    const dispatch: any = useDispatch();
    document.title = "Monitorização de Stock | Xplendor";
    const { analytics, loading } = useSelector(selectVM);

    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        return a ? Number(JSON.parse(a).company_id || 0) : 0;
    }, []);

    useEffect(() => {
        if (companyId) dispatch(getAnalyticsDashboard({ companyId }));
    }, [companyId, dispatch]);

    const mp = analytics?.marketing_performance;
    const kpis = [
        { label: "Visualizações (7 dias)", value: nfmt(mp?.views_last_7_days), icon: "ri-eye-line", color: "primary" },
        { label: "Leads (7 dias)", value: nfmt(mp?.leads_last_7_days), icon: "ri-user-add-line", color: "success" },
        { label: "Interações (7 dias)", value: nfmt(mp?.interactions_last_7_days), icon: "ri-cursor-line", color: "info" },
        { label: "Taxa de interesse", value: `${Number(mp?.interest_rate ?? 0)}%`, icon: "ri-line-chart-line", color: "warning" },
    ];

    return (
        <div className="page-content">
            <Container fluid>
                <Row className="mb-3">
                    <Col>
                        <h4 className="mb-1"><i className="ri-dashboard-3-line text-primary me-2" />Monitorização de Stock</h4>
                        <p className="text-muted mb-0">O teu stock e a procura, num só sítio.</p>
                    </Col>
                </Row>

                {loading && !analytics ? (
                    <div className="d-flex align-items-center gap-2 text-muted py-5"><Spinner size="sm" /> A carregar…</div>
                ) : !analytics ? (
                    <p className="text-muted py-4">Sem dados de stock para mostrar.</p>
                ) : (
                    <>
                        {/* 1+2 — Total / preço médio / km médio / tempo médio em stock (reutiliza SummaryDashboard) */}
                        <Row className="g-4 mb-4">
                            {analytics.summary && <SummaryDashboard summary={analytics.summary} />}
                        </Row>

                        {/* 4+5 — Visualizações, leads e interações (marketing_performance, já existente) */}
                        <Row className="g-4 mb-4">
                            {kpis.map((k) => (
                                <Col key={k.label} xs={6} xl={3}>
                                    <Card className="mb-0">
                                        <CardBody className="d-flex align-items-center gap-3">
                                            <span className="avatar-sm flex-shrink-0">
                                                <span className={`avatar-title bg-${k.color}-subtle text-${k.color} rounded fs-20`}><i className={k.icon} /></span>
                                            </span>
                                            <div className="min-w-0">
                                                <div className="fs-20 fw-semibold text-truncate">{k.value}</div>
                                                <small className="text-muted">{k.label}</small>
                                            </div>
                                        </CardBody>
                                    </Card>
                                </Col>
                            ))}
                        </Row>

                        {/* 3 — Procura: maior procura + muito vistos/poucos contactos */}
                        <Row className="g-4 mb-4">
                            <Col lg={6}>
                                <DemandList
                                    title="Mais procurados"
                                    hint="As viaturas com mais visualizações no teu stock."
                                    cars={analytics.top_interest_cars ?? []}
                                    metric="views"
                                />
                            </Col>
                            <Col lg={6}>
                                <DemandList
                                    title="Muito vistos, poucos contactos"
                                    hint="Atraem visitas mas convertem pouco — vale a pena rever preço/anúncio."
                                    cars={analytics.low_lead_cars ?? []}
                                    metric="lowlead"
                                />
                            </Col>
                        </Row>

                        {/* 6-9 — Métricas dependentes: "Em breve" com a dependência */}
                        <Row className="mb-3">
                            <Col>
                                <h5 className="mb-0 mt-2"><i className="ri-time-line text-muted me-2" />Em breve</h5>
                                <p className="text-muted fs-13">Métricas previstas, à espera das funcionalidades de que dependem.</p>
                            </Col>
                        </Row>
                        <Row className="g-4 pb-5 mb-5">
                            <ComingSoonCard icon="ri-heart-3-line" title="Favoritos" depends="Disponível com a Área de Cliente (login de visitante)." />
                            <ComingSoonCard icon="ri-scales-3-line" title="Comparações" depends="Disponível com a comparação de viaturas (Área de Cliente)." />
                            <ComingSoonCard icon="ri-qr-code-line" title="QR scans" depends="Disponível com o QR Code por veículo." />
                            <ComingSoonCard icon="ri-group-line" title="Faixa etária dos visitantes" depends="Via Google Analytics (não é dado da plataforma)." />
                        </Row>
                    </>
                )}
            </Container>
        </div>
    );
};

export default StockMonitoring;
