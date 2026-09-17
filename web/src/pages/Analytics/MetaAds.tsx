import React, { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Spinner } from "reactstrap";
import BreadCrumb from "Components/Common/BreadCrumb";
import { getMetaOverview } from "helpers/laravel_helper";
import { MetaOverviewResponse, eur, nfmt, pct } from "common/models/metaAds.model";

/**
 * XPLENDOR — Meta / Anúncios (LEITURA). Mostra os dados FACTUAIS que o pipeline
 * já ingere: gasto, impressões, cliques, CTR/CPC, por campanha, tendência, e as
 * vendas atribuídas a campanhas (já calculadas pelo motor). ZERO interpretação/IA.
 */

const RANGES = [
    { days: 7, label: "7 dias" },
    { days: 28, label: "28 dias" },
    { days: 90, label: "90 dias" },
];

const MetaAds = () => {
    document.title = "Meta / Anúncios | Xplendor";

    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        return a ? Number(JSON.parse(a).company_id || 0) : 0;
    }, []);

    const [days, setDays] = useState(28);
    const [loading, setLoading] = useState(true);
    const [data, setData] = useState<MetaOverviewResponse | null>(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (!companyId) { setLoading(false); return; }
        let alive = true;
        setLoading(true); setError(false);
        getMetaOverview(companyId, days)
            .then((r: any) => { if (alive) setData(r?.data ?? null); })
            .catch(() => { if (alive) setError(true); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, days]);

    const kpis = useMemo(() => {
        if (!data) return [];
        const o = data.overview;
        return [
            { label: "Gasto", value: eur(o.spend), icon: "ri-money-euro-circle-line", color: "primary" },
            { label: "Impressões", value: nfmt(o.impressions), icon: "ri-eye-line", color: "info" },
            { label: "Cliques", value: nfmt(o.clicks), icon: "ri-cursor-line", color: "secondary" },
            { label: "CTR", value: pct(o.ctr), icon: "ri-percent-line", color: "success" },
            { label: "CPC", value: eur(o.cpc), icon: "ri-coin-line", color: "warning" },
        ];
    }, [data]);

    const rangeToggle = (
        <div className="btn-group" role="group" aria-label="Intervalo">
            {RANGES.map((r) => (
                <button key={r.days} type="button" className={"btn btn-sm " + (days === r.days ? "btn-primary" : "btn-outline-primary")} onClick={() => setDays(r.days)}>{r.label}</button>
            ))}
        </div>
    );

    return (
        <div className="page-content">
            <Container fluid>
                <BreadCrumb title="Meta / Anúncios" pageTitle="Análise" />

                {loading ? (
                    <div className="d-flex justify-content-center py-5"><Spinner color="primary" /></div>
                ) : error || !data ? (
                    <Card><CardBody className="text-center py-5">
                        <div className="avatar-md mx-auto mb-3"><span className="avatar-title bg-danger-subtle text-danger rounded fs-24"><i className="ri-error-warning-line" /></span></div>
                        <h5 className="mb-2">Não foi possível carregar os dados Meta</h5>
                    </CardBody></Card>
                ) : !data.connected ? (
                    <Card><CardBody className="text-center py-5">
                        <div className="avatar-md mx-auto mb-3"><span className="avatar-title bg-light rounded fs-24" style={{ color: "#1877F2" }}><i className="ri-facebook-circle-line" /></span></div>
                        <h5 className="mb-2">Liga a conta Meta</h5>
                        <p className="text-muted mb-3">Ainda não ligaste a conta Meta Ads deste stand.</p>
                        <Link to={`/companies/${companyId}`} className="btn btn-primary"><i className="ri-links-line me-1" />Ir às Integrações</Link>
                    </CardBody></Card>
                ) : (
                    <>
                        <Row className="mb-3 align-items-center">
                            <Col>
                                <p className="text-muted mb-0 fs-13">
                                    {data.range.start} a {data.range.end}
                                    {data.last_synced_at ? ` · último sync ${new Date(data.last_synced_at).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" })}` : ""}
                                </p>
                            </Col>
                            <Col xs="auto">{rangeToggle}</Col>
                        </Row>

                        {/* KPIs factuais */}
                        <Row className="g-4 mb-4">
                            {kpis.map((c) => (
                                <Col key={c.label} xs={6} lg>
                                    <Card className="mb-0"><CardBody className="d-flex align-items-center gap-3">
                                        <span className="avatar-sm flex-shrink-0"><span className={`avatar-title bg-${c.color}-subtle text-${c.color} rounded fs-20`}><i className={c.icon} /></span></span>
                                        <div className="min-w-0"><div className="fs-18 fw-semibold text-truncate">{c.value}</div><small className="text-muted">{c.label}</small></div>
                                    </CardBody></Card>
                                </Col>
                            ))}
                        </Row>

                        <Row className="g-4 pb-5 mb-5">
                            {/* Por campanha */}
                            <Col xl={7}>
                                <Card className="h-100 mb-0"><CardBody>
                                    <h6 className="mb-3 text-uppercase">Por campanha</h6>
                                    {data.by_campaign.length === 0 ? <p className="text-muted fs-13 mb-0">Sem dados de campanhas no período.</p> : (
                                        <div className="table-responsive">
                                            <table className="table table-sm align-middle mb-0">
                                                <thead className="text-muted"><tr><th>Campanha</th><th className="text-end">Gasto</th><th className="text-end">Impr.</th><th className="text-end">Cliques</th><th className="text-end">CTR</th></tr></thead>
                                                <tbody>
                                                    {data.by_campaign.map((c) => (
                                                        <tr key={String(c.campaign_id)}>
                                                            <td className="fw-medium text-truncate" style={{ maxWidth: 220 }}>{c.campaign_name}</td>
                                                            <td className="text-end">{eur(c.spend)}</td>
                                                            <td className="text-end">{nfmt(c.impressions)}</td>
                                                            <td className="text-end">{nfmt(c.clicks)}</td>
                                                            <td className="text-end">{pct(c.ctr)}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </CardBody></Card>
                            </Col>

                            {/* Vendas atribuídas (factual, já calculado) */}
                            <Col xl={5}>
                                <Card className="h-100 mb-0"><CardBody>
                                    <h6 className="mb-1 text-uppercase">Vendas atribuídas</h6>
                                    <p className="text-muted fs-12 mb-3">Vendas ligadas a campanhas Meta pelo motor de atribuição (best-effort).</p>
                                    <div className="d-flex gap-3 mb-3">
                                        <div><div className="fs-20 fw-semibold">{nfmt(data.attributed.sales)}</div><small className="text-muted">Vendas</small></div>
                                        <div><div className="fs-20 fw-semibold">{eur(data.attributed.revenue)}</div><small className="text-muted">Receita</small></div>
                                        {data.attributed.avg_confidence != null && (
                                            <div><div className="fs-20 fw-semibold">{data.attributed.avg_confidence}</div><small className="text-muted">Confiança méd.</small></div>
                                        )}
                                    </div>
                                    {data.attributed.by_campaign.length === 0 ? (
                                        <p className="text-muted fs-13 mb-0">Sem vendas atribuídas no período.</p>
                                    ) : (
                                        <ul className="list-unstyled vstack gap-2 mb-0">
                                            {data.attributed.by_campaign.map((c) => (
                                                <li key={String(c.campaign_id)} className="d-flex justify-content-between fs-13">
                                                    <span className="text-truncate me-2">{c.campaign_name}</span>
                                                    <span className="fw-medium flex-shrink-0">{nfmt(c.sales)} · {eur(c.revenue)}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </CardBody></Card>
                            </Col>
                        </Row>
                    </>
                )}
            </Container>
        </div>
    );
};

export default MetaAds;
