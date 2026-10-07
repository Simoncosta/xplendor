import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, CardHeader, Col, Container, Row, Spinner } from "reactstrap";
import BreadCrumb from "Components/Common/BreadCrumb";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { getBaseDashboard } from "helpers/laravel_helper";
import { STAGE_META, STAGE_ORDER, Stage, fmtInt } from "common/models/editorialWorkflow.model";
import XplendorChargesNotice from "pages/Charges/XplendorChargesNotice";
import SubscriptionTrialBanner from "./components/SubscriptionTrialBanner";

/**
 * Dashboard base (empresas sem os módulos de viaturas nem de restauração): o mês da Linha
 * Editorial (por etapa, para publicar hoje, atrasadas, à espera de aprovação), os
 * seguidores (último valor e crescimento) e as faturas da XPLENDOR. Cada bloco segue os
 * módulos ativos da empresa.
 */
type Editorial = { month: string; by_stage: Partial<Record<Stage, number>>; total: number; today: number; overdue: number; awaiting: number };
type Follower = { current: { count: number; date: string; source: string } | null; growth_30d: number | null; since: string | null };
type Payload = { modules: string[]; editorial: Editorial | null; followers: Record<string, Follower> | null };

const MONTHS_PT = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];
const monthLabel = (key: string) => { const [y, m] = key.split("-"); return `${MONTHS_PT[Number(m) - 1]} ${y}`; };
const PLATFORMS: { key: string; label: string; icon: string }[] = [
    { key: "instagram", label: "Instagram", icon: "ri-instagram-line text-danger" },
    { key: "facebook", label: "Página de Facebook", icon: "ri-facebook-circle-line text-primary" },
];
const dm = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit" });

export default function BaseDashboard() {
    const companyId = useWorkingCompanyId();
    const [data, setData] = useState<Payload | null>(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        setData(null);
        setError(false);
        getBaseDashboard(companyId).then((r: any) => setData(r?.data ?? null)).catch(() => setError(true));
    }, [companyId]);

    const ed = data?.editorial ?? null;
    const link = (vista: string) => `/editorial?vista=${vista}${ed ? `&mes=${ed.month}` : ""}`;
    const stat = (label: string, n: number, tone: string, icon: string, to: string) => (
        <Col xs={6} md={3} key={label}>
            <Link to={to} className="d-block border rounded p-3 h-100 text-body text-decoration-none" aria-label={`${label}: ${n}`}>
                <div className="text-muted fs-12 mb-1"><i className={`${icon} me-1 text-${tone}`} />{label}</div>
                <div className={`fs-22 fw-semibold ${n > 0 ? `text-${tone}` : ""}`}>{n}</div>
            </Link>
        </Col>
    );

    return (
        <div className="page-content">
            <Container fluid>
                <BreadCrumb title="Dashboard" pageTitle="Início" />
                <Row className="g-3 mb-3"><SubscriptionTrialBanner /></Row>
                <XplendorChargesNotice />

                {error ? <Card><CardBody className="text-muted">Não foi possível carregar o dashboard.</CardBody></Card>
                    : !data ? <div className="text-center py-5"><Spinner color="primary" /></div> : (
                        <Row className="g-3" data-testid="base-dashboard">
                            {ed && (
                                <Col xl={8}>
                                    <Card className="h-100">
                                        <CardHeader className="d-flex flex-wrap align-items-center gap-2">
                                            <div className="me-auto">
                                                <h5 className="mb-0">Linha Editorial</h5>
                                                <small className="text-muted">{monthLabel(ed.month)}: {ed.total} {ed.total === 1 ? "publicação" : "publicações"}</small>
                                            </div>
                                            <Link to={link("calendario")} className="btn btn-soft-primary btn-sm"><i className="ri-calendar-2-line me-1" />Abrir a Linha Editorial</Link>
                                        </CardHeader>
                                        <CardBody>
                                            <Row className="g-2 mb-3">
                                                {stat("Para publicar hoje", ed.today, "success", "ri-send-plane-line", link("calendario"))}
                                                {stat("Atrasadas", ed.overdue, "danger", "ri-alarm-warning-line", link("calendario"))}
                                                {stat("À espera de aprovação", ed.awaiting, "warning", "ri-time-line", link("kanban"))}
                                                {stat("Em produção", (ed.by_stage.production ?? 0) + (ed.by_stage.internal_review ?? 0), "primary", "ri-palette-line", link("kanban"))}
                                            </Row>
                                            <div className="text-muted fs-12 mb-2">O mês por etapa</div>
                                            {ed.total === 0 ? <p className="text-muted mb-0">Ainda não há publicações neste mês.</p> : (
                                                <>
                                                    <div className="d-flex rounded overflow-hidden mb-2" style={{ height: 10 }} aria-hidden>
                                                        {STAGE_ORDER.filter((s) => (ed.by_stage[s] ?? 0) > 0).map((s) => (
                                                            <div key={s} style={{ width: `${((ed.by_stage[s] ?? 0) / ed.total) * 100}%`, background: STAGE_META[s].hex }} />
                                                        ))}
                                                    </div>
                                                    <div className="d-flex flex-wrap gap-3 fs-13">
                                                        {STAGE_ORDER.filter((s) => (ed.by_stage[s] ?? 0) > 0).map((s) => (
                                                            <span key={s} className="d-inline-flex align-items-center gap-1">
                                                                <span className="rounded-circle d-inline-block" style={{ width: 8, height: 8, background: STAGE_META[s].hex }} />
                                                                {STAGE_META[s].label} <strong>{ed.by_stage[s]}</strong>
                                                            </span>
                                                        ))}
                                                    </div>
                                                </>
                                            )}
                                        </CardBody>
                                    </Card>
                                </Col>
                            )}
                            {data.followers && (
                                <Col xl={ed ? 4 : 6}>
                                    <Card className="h-100">
                                        <CardHeader className="d-flex align-items-center gap-2">
                                            <h5 className="mb-0 me-auto">Seguidores</h5>
                                            <Link to="/brand-profile" className="btn btn-soft-secondary btn-sm">Perfil da Marca</Link>
                                        </CardHeader>
                                        <CardBody className="vstack gap-3">
                                            {PLATFORMS.map((p) => {
                                                const f = data.followers?.[p.key];
                                                return (
                                                    <div key={p.key} className="d-flex align-items-center gap-3">
                                                        <i className={`${p.icon} fs-24`} />
                                                        <div className="flex-grow-1 min-w-0">
                                                            <div className="text-muted fs-12">{p.label}</div>
                                                            {f?.current ? (
                                                                <>
                                                                    <div className="fs-18 fw-semibold">{fmtInt(f.current.count)}</div>
                                                                    <div className="fs-12 text-muted">
                                                                        {f.current.source === "manual" ? "Registo manual" : "Leitura automática"} de {dm(f.current.date)}
                                                                    </div>
                                                                </>
                                                            ) : <div className="fs-13 text-muted">Sem registos. Registe o valor no Perfil da Marca.</div>}
                                                        </div>
                                                        {f?.growth_30d !== null && f?.growth_30d !== undefined && (
                                                            <span className={`fs-13 fw-medium text-nowrap ${f.growth_30d > 0 ? "text-success" : f.growth_30d < 0 ? "text-danger" : "text-muted"}`}
                                                                title={f.since ? `Desde ${dm(f.since)}` : undefined}>
                                                                {f.growth_30d > 0 ? "+" : ""}{fmtInt(f.growth_30d)} em 30 dias
                                                            </span>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </CardBody>
                                    </Card>
                                </Col>
                            )}
                            {!ed && !data.followers && (
                                <Col xs={12}>
                                    <Card><CardBody className="text-muted">Esta empresa ainda não tem módulos com dados para o dashboard. Os blocos aparecem quando a Linha Editorial ou o Marketing estiverem ativos.</CardBody></Card>
                                </Col>
                            )}
                        </Row>
                    )}
            </Container>
        </div>
    );
}
