import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, Container, Row, Col, Spinner } from "reactstrap";
import BreadCrumb from "Components/Common/BreadCrumb";
import {
    getAdminTicketsSummary, getAdminTicketsQuotePipeline, getAdminQuotesSummary,
    getAdminCompanies, getAdminPlatformSummary,
} from "helpers/laravel_helper";

/**
 * XPLENDOR — Dashboard do ROOT (gestão da plataforma, não o dos clientes). Duas zonas:
 * Trabalho Pendente (o que precisa de ação) e Escala (tamanho da plataforma). Só métricas
 * REAIS (o spike confirmou que não há faturação agregada). Valores € = PIPELINE COMERCIAL,
 * NUNCA faturação das empresas. Renderizado só quando é root de verdade (não em impersonation).
 */

const euro = (v: any) => Number(v || 0).toLocaleString("pt-PT", { style: "currency", currency: "EUR", minimumFractionDigits: 0, maximumFractionDigits: 0 });
const num = (v: any) => Number(v || 0).toLocaleString("pt-PT");

// Estados do pipeline site_change a destacar (os que "estão em cima da mesa").
const PIPELINE = [
    { key: "awaiting_quote", label: "Por orçamentar", color: "secondary" },
    { key: "quoted", label: "Orçamentado", color: "warning" },
    { key: "approved", label: "Aprovado", color: "info" },
    { key: "paid", label: "Pago", color: "success" },
];

// Card de estatística simples (reutilizado nas duas zonas).
function Stat({ icon, color, label, value, sub, to }: { icon: string; color: string; label: string; value: string; sub?: string; to?: string }) {
    const body = (
        <Card className="mb-0 h-100"><CardBody className="d-flex align-items-center gap-3">
            <div className="avatar-sm flex-shrink-0">
                <span className={`avatar-title bg-${color}-subtle text-${color} rounded fs-3`}><i className={icon} /></span>
            </div>
            <div className="flex-grow-1">
                <p className="text-muted mb-1 fs-13">{label}</p>
                <h4 className="mb-0">{value}</h4>
                {sub && <small className="text-muted">{sub}</small>}
            </div>
        </CardBody></Card>
    );
    return to ? <Link to={to} className="text-reset text-decoration-none d-block h-100">{body}</Link> : body;
}

export default function RootDashboard() {
    document.title = "Painel de gestão | Xplendor";

    const [loading, setLoading] = useState(true);
    const [tickets, setTickets] = useState<any>(null);
    const [pipeline, setPipeline] = useState<any>(null);
    const [quotes, setQuotes] = useState<any>(null);
    const [platform, setPlatform] = useState<any>(null);
    const [companies, setCompanies] = useState<any[]>([]);

    useEffect(() => {
        let alive = true;
        Promise.allSettled([
            getAdminTicketsSummary(),
            getAdminTicketsQuotePipeline(),
            getAdminQuotesSummary(),
            getAdminPlatformSummary(),
            getAdminCompanies(),
        ]).then((res) => {
            if (!alive) return;
            const val = (i: number) => (res[i].status === "fulfilled" ? (res[i] as PromiseFulfilledResult<any>).value?.data : null);
            setTickets(val(0));
            setPipeline(val(1));
            setQuotes(val(2));
            setPlatform(val(3));
            setCompanies(val(4)?.companies ?? []);
            setLoading(false);
        });
        return () => { alive = false; };
    }, []);

    const empresas = useMemo(() => {
        const total = companies.length;
        const ativas = companies.filter((c) => c.has_access).length;
        return { total, ativas, inativas: total - ativas };
    }, [companies]);

    const pendentesTickets = Number(tickets?.pending ?? 0); // open + in_review
    const approvedValue = quotes?.approved_value ?? 0;
    const pipelineTotal = pipeline?.total ?? { count: 0, amount: 0, hours: 0 };

    if (loading) {
        return (
            <div className="page-content"><Container fluid>
                <BreadCrumb title="Painel de gestão" pageTitle="Xplendor" />
                <div className="text-center py-5"><Spinner color="primary" /></div>
            </Container></div>
        );
    }

    return (
        <div className="page-content">
            <Container fluid>
                <BreadCrumb title="Painel de gestão" pageTitle="Xplendor" />

                {/* ───────── ZONA 1 — TRABALHO PENDENTE ───────── */}
                <div className="d-flex align-items-center gap-2 mb-2">
                    <i className="ri-todo-line text-primary fs-4" />
                    <h5 className="mb-0">Trabalho pendente</h5>
                </div>
                <Row className="g-3 mb-2">
                    <Col md={4}>
                        <Stat icon="ri-customer-service-2-line" color="warning" label="Tickets por tratar"
                            value={num(pendentesTickets)}
                            sub={`${num(tickets?.open ?? 0)} abertos · ${num(tickets?.in_review ?? 0)} em análise`}
                            to="/admin" />
                    </Col>
                    <Col md={4}>
                        <Stat icon="ri-file-list-3-line" color="info" label="Orçamentos pendentes"
                            value={num(quotes?.pending ?? 0)}
                            sub={`${num(quotes?.approved ?? 0)} aprovados · ${num(quotes?.rejected ?? 0)} rejeitados`}
                            to="/admin/quotes" />
                    </Col>
                    <Col md={4}>
                        <Stat icon="ri-price-tag-3-line" color="secondary" label="Pipeline site_change"
                            value={euro(pipelineTotal.amount)}
                            sub={`${num(pipelineTotal.count)} pedidos · ${num(pipelineTotal.hours)}h · pipeline comercial`}
                            to="/admin" />
                    </Col>
                </Row>

                {/* Pipeline site_change por estado (nº · € · horas) */}
                {pipeline?.by_status && (
                    <Row className="g-2 mb-2">
                        {PIPELINE.map((p) => {
                            const b = pipeline.by_status[p.key] ?? { count: 0, amount: 0, hours: 0 };
                            return (
                                <Col key={p.key} xs={6} md={3}>
                                    <Card className="mb-0"><CardBody className="py-2 px-3">
                                        <small className="text-muted text-uppercase" style={{ fontSize: "0.68rem", letterSpacing: "0.04em" }}>{p.label}</small>
                                        <div className={`fs-18 fw-semibold text-${p.color}`}>{euro(b.amount)}</div>
                                        <small className="text-muted">{num(b.count)} · {num(b.hours)}h</small>
                                    </CardBody></Card>
                                </Col>
                            );
                        })}
                    </Row>
                )}

                {/* Nota clara: valores € são pipeline comercial, não faturação das empresas. */}
                <p className="text-muted fs-12 mb-4"><i className="ri-information-line me-1" />Os valores em € são <strong>pipeline comercial</strong> (orçamentos/pedidos) — não são faturação das empresas.</p>

                {/* ───────── ZONA 2 — ESCALA ───────── */}
                <div className="d-flex align-items-center gap-2 mb-2">
                    <i className="ri-bar-chart-grouped-line text-primary fs-4" />
                    <h5 className="mb-0">Escala da plataforma</h5>
                </div>
                <Row className="g-3 mb-2">
                    <Col md={4}>
                        <Stat icon="ri-building-line" color="primary" label="Empresas"
                            value={num(empresas.total)}
                            sub={`${num(empresas.ativas)} ativas · ${num(empresas.inativas)} inativas`}
                            to="/companies" />
                    </Col>
                    <Col md={4}>
                        <Stat icon="ri-team-line" color="success" label="Utilizadores"
                            value={num(platform?.users_total ?? 0)} sub="total da plataforma" />
                    </Col>
                    <Col md={4}>
                        <Stat icon="ri-car-line" color="info" label="Carros"
                            value={num(platform?.cars_total ?? 0)} sub="total da plataforma" />
                    </Col>
                </Row>

                {/* Orçamentos aprovados (€) — pipeline comercial, rotulado. */}
                <Row className="g-3 mb-5">
                    <Col md={4}>
                        <Stat icon="ri-money-euro-circle-line" color="success" label="Orçamentos aprovados (€)"
                            value={euro(approvedValue)} sub="pipeline comercial (não é faturação)" to="/admin/quotes" />
                    </Col>
                </Row>
            </Container>
        </div>
    );
}
