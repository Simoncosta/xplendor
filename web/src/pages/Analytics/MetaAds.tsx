import React, { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Alert, Card, CardBody, Col, Container, Row, Spinner } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import { getMetaOverview } from "helpers/laravel_helper";
import { MetaOverviewResponse, MetaOverviewState, eur, nfmt, pct } from "common/models/metaAds.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

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

const POLL_INTERVAL_MS = 15000;
const POLL_MAX_TICKS = 80; // ~20 min

const MetaAds = () => {
    document.title = "Meta / Anúncios | Xplendor";

    const companyId = useWorkingCompanyId();

    const [days, setDays] = useState(28);
    const [loading, setLoading] = useState(true);
    const [data, setData] = useState<MetaOverviewResponse | null>(null);
    const [error, setError] = useState(false);
    // Refresca em silêncio enquanto o 1.º backfill corre (os dados aparecem sozinhos).
    // `fetchedAt` muda a CADA resposta (sucesso ou erro) para o polling nunca morrer
    // à primeira falha de rede; pára ao fim de POLL_MAX_TICKS (~20 min).
    const [tick, setTick] = useState(0);
    const [fetchedAt, setFetchedAt] = useState(0);

    useEffect(() => {
        if (!companyId) { setLoading(false); return; }
        let alive = true;
        if (tick === 0) { setLoading(true); setError(false); }
        getMetaOverview(companyId, days)
            .then((r: any) => { if (alive) setData(r?.data ?? null); })
            .catch(() => { if (alive && tick === 0) setError(true); })
            .finally(() => { if (alive) { setLoading(false); setFetchedAt(Date.now()); } });
        return () => { alive = false; };
    }, [companyId, days, tick]);

    const pollExhausted = tick >= POLL_MAX_TICKS;
    useEffect(() => {
        if (data?.state !== "syncing_first" || pollExhausted) return;
        const t = setTimeout(() => setTick((v) => v + 1), POLL_INTERVAL_MS);
        return () => clearTimeout(t);
    }, [data, fetchedAt, pollExhausted]);

    const changeDays = (d: number) => { setTick(0); setDays(d); };

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
        <div className="xp-seg" role="radiogroup" aria-label="Intervalo">
            {RANGES.map((r) => (
                <button key={r.days} type="button" role="radio" aria-checked={days === r.days} className={days === r.days ? "on" : ""} onClick={() => changeDays(r.days)}>{r.label}</button>
            ))}
        </div>
    );

    // Há números para mostrar (o intervalo e a data do último sync vão para o cabeçalho).
    const showData = !loading && !error && !!data && data.connected && !(data.source === "none" && data.state && BLOCKING_STATES.includes(data.state));

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader title="Meta / Anúncios" breadcrumbs={[{ label: "Marketing" }]}
                    description={showData && data ? (
                        <>
                            {data.range.start} a {data.range.end}
                            {data.last_synced_at ? ` · último sync ${new Date(data.last_synced_at).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" })}` : ""}
                        </>
                    ) : "Os resultados dos anúncios da Meta (Facebook e Instagram)."}
                    actions={showData ? rangeToggle : undefined} />

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
                        <h5 className="mb-2">Ligue a conta Meta</h5>
                        <p className="text-muted mb-3">Ainda não ligou a conta Meta Ads desta empresa.</p>
                        <Link to={integrationsUrl(companyId)} className="btn btn-primary"><i className="ri-links-line me-1" />Ir às Integrações</Link>
                    </CardBody></Card>
                ) : data.source === "none" && data.state && BLOCKING_STATES.includes(data.state) ? (
                    // Sem números para mostrar: o ESTADO explica porquê (nunca zeros falsos).
                    <StateCard state={data.state} error={data.sync?.error ?? null} companyId={companyId} slow={pollExhausted} />
                ) : (
                    <>
                        {data.state && <StateBanner state={data.state} error={data.sync?.error ?? null} companyId={companyId} slow={pollExhausted} />}
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
                                    {data.by_campaign.length === 0 ? <p className="text-muted fs-13 mb-0">{data.state === "no_spend" ? "Sem gasto neste período." : "Sem dados de campanhas no período."}</p> : (
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

/** Estados em que, sem dados, se mostra um cartão explicativo em vez de zeros. */
const BLOCKING_STATES: MetaOverviewState[] = ["token_expired", "needs_account", "syncing_first", "sync_failed"];

type StateProps = { state: MetaOverviewState; error: string | null; companyId: number; slow?: boolean };

/** Abre o perfil da empresa directamente no separador Integrações. */
const integrationsUrl = (companyId: number) => `/companies/${companyId}?tab=integrations`;

const SLOW_TEXT = "Está a demorar mais do que o normal. Recarregue a página daqui a pouco; se continuar, confirme a conta de anúncios nas Integrações.";

const STATE_COPY: Partial<Record<MetaOverviewState, { title: string; text: string; color: string; icon: string; cta?: string }>> = {
    token_expired: {
        title: "Sessão Meta expirada: volte a ligar",
        text: "A ligação à Meta expirou, por isso os dados deixaram de ser atualizados. Volte a ligar a conta nas Integrações.",
        color: "danger", icon: "ri-error-warning-line", cta: "Reconectar a Meta",
    },
    needs_account: {
        title: "Falta escolher a conta de anúncios",
        text: "A Meta está ligada, mas ainda não indicou qual é a conta de anúncios desta empresa. Escolha-a nas Integrações.",
        color: "warning", icon: "ri-advertisement-line", cta: "Escolher a conta",
    },
    syncing_first: {
        title: "A sincronizar pela primeira vez…",
        text: "Estamos a buscar os últimos 90 dias de anúncios à Meta. Os dados aparecem aqui sozinhos dentro de momentos.",
        color: "info", icon: "ri-refresh-line",
    },
    sync_failed: {
        title: "A sincronização com a Meta falhou",
        text: "Não foi possível buscar os dados à Meta. Confirme a conta de anúncios nas Integrações e tente de novo.",
        color: "danger", icon: "ri-close-circle-line", cta: "Ver Integrações",
    },
};

const StateCard = ({ state, error, companyId, slow }: StateProps) => {
    const c = STATE_COPY[state];
    if (!c) return null;
    return (
        <Card><CardBody className="text-center py-5">
            <div className="avatar-md mx-auto mb-3">
                <span className={`avatar-title bg-${c.color}-subtle text-${c.color} rounded fs-24`}>
                    {state === "syncing_first" ? <Spinner size="sm" /> : <i className={c.icon} />}
                </span>
            </div>
            <h5 className="mb-2">{c.title}</h5>
            <p className="text-muted mb-3 mx-auto" style={{ maxWidth: 520 }}>{state === "syncing_first" && slow ? SLOW_TEXT : c.text}</p>
            {state === "sync_failed" && error && <p className="text-danger fs-13 mb-3">{error}</p>}
            {state === "syncing_first" && slow && <Link to={integrationsUrl(companyId)} className="btn btn-outline-primary"><i className="ri-links-line me-1" />Ver Integrações</Link>}
            {c.cta && <Link to={integrationsUrl(companyId)} className="btn btn-primary"><i className="ri-links-line me-1" />{c.cta}</Link>}
        </CardBody></Card>
    );
};

/** Banner por cima dos dados (há números para mostrar, mas o estado merece aviso). */
const StateBanner = ({ state, error, companyId, slow }: StateProps) => {
    if (state === "ok") return null;
    if (state === "no_spend") {
        return (
            <Alert color="light" className="d-flex align-items-center gap-2 fs-13">
                <i className="ri-information-line fs-16" />
                Sem gasto neste período: a ligação está correta. Experimente um intervalo maior.
            </Alert>
        );
    }
    const c = STATE_COPY[state];
    if (!c) return null;
    const text = state === "sync_failed"
        ? `Os números podem não estar atualizados.${error ? ` Motivo: ${error}` : ""}`
        : state === "syncing_first" && slow ? SLOW_TEXT : c.text;
    return (
        <Alert color={c.color} className="d-flex align-items-start justify-content-between gap-3 flex-wrap fs-13">
            <div>
                <strong className="d-block mb-1">{state === "syncing_first" && !slow && <Spinner size="sm" className="me-2" />}{c.title}</strong>
                {text}
            </div>
            {c.cta && <Link to={integrationsUrl(companyId)} className="btn btn-sm btn-primary">{c.cta}</Link>}
        </Alert>
    );
};

export default MetaAds;
