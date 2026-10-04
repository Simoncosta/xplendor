import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Alert, Button, ButtonGroup, Card, CardBody, Col, Row, Spinner, Table } from "reactstrap";
import { getAutomotiveHub, getAutomotiveHubFunnel } from "helpers/laravel_helper";
import type { AutomotiveFunnel, AutomotiveHub, FunnelRow, FunnelSortKey, HubPrice, SortDirection } from "common/models/automotiveHub.model";
import type { Recommendation, RecommendationLevel } from "common/models/recommendation.model";
import Pagination from "Components/Common/Pagination";
import { MetricCard } from "./AutomotiveMarketingBlock";
import { eur2 } from "./automotiveMarketingText";
import { eur0, int } from "./restaurantMarketingText";

/**
 * XPLENDOR — Hub do Automóvel: separador "Stock" do dashboard.
 *
 *   · RESUMO: viaturas em stock, dias médios, capital parado, % acima do mercado
 *     (só comparações exatas) e investimento Meta a 30 dias;
 *   · RECOMENDAÇÕES: o motor novo (o MESMO do contador do separador), uma por viatura;
 *   · FUNIL POR VIATURA (14 ou 30 dias): vistas, contactos, leads, venda, investimento, CPL;
 *   · AVISOS DE QUALIDADE (só se existirem). Sem atalhos genéricos: só as ações.
 * Cartões sobre o fundo da página, sem cartão dentro de cartão. Os números vêm
 * das fontes únicas do backend; aqui só se apresentam.
 */

const LEVEL_UI: Record<RecommendationLevel, { label: string; color: string }> = {
    high: { label: "Prioridade alta", color: "danger" },
    medium: { label: "Prioridade média", color: "warning" },
    low: { label: "Prioridade baixa", color: "info" },
};

const isExternal = (url: string) => /^https?:\/\//i.test(url);
const dm = (date: string | null) => (date ? `${date.slice(8, 10)}/${date.slice(5, 7)}` : "");
const pct1 = (v: number) => `${v.toLocaleString("pt-PT", { maximumFractionDigits: 1 })}%`;

const APPROX_HELP = "Comparação aproximada: os anúncios comparáveis não são do mesmo modelo (por exemplo, da mesma categoria ou da mesma marca e faixa de preço). "
    + "Serve de referência, mas não conta como acima do mercado.";

// ── Preço face ao mercado (exato / aproximado) ───────────────────────────────

const POSITION_UI: Record<HubPrice["display_position"], { label: string; color: string }> = {
    above_market: { label: "Acima do mercado", color: "danger" },
    aligned_market: { label: "Alinhado", color: "success" },
    below_market: { label: "Abaixo do mercado", color: "info" },
    insufficient_data: { label: "Sem dados", color: "light" },
    approximate_comparison: { label: "Comparação aproximada", color: "secondary" },
};

const LOW_CONFIDENCE_HELP = "Há comparação com anúncios do mesmo modelo, mas são poucos para indicar uma posição com confiança.";

const PriceBadge = ({ price }: { price: HubPrice }) => {
    // Comparação existe mas com poucos comparáveis (o sinal foi suprimido): não é "sem dados".
    const lowConfidence = price.display_position === "insufficient_data" && price.comparison !== null;
    const ui = lowConfidence
        ? { label: "Poucos comparáveis", color: "light" }
        : POSITION_UI[price.display_position] ?? POSITION_UI.insufficient_data;
    const diff = price.difference_pct !== null && price.display_position !== "insufficient_data"
        ? ` ${price.difference_pct > 0 ? "+" : ""}${price.difference_pct.toLocaleString("pt-PT", { maximumFractionDigits: 1 })}%` : "";
    return (
        <span className="d-inline-flex flex-column gap-1">
            <span
                className={`badge bg-${ui.color}-subtle text-${ui.color === "light" ? "muted" : ui.color} fw-normal`}
                title={price.display_position === "approximate_comparison" ? APPROX_HELP : lowConfidence ? LOW_CONFIDENCE_HELP : undefined}
            >
                {ui.label}{diff}
            </span>
            {price.criteria_widened && (
                <span className="text-muted fs-11" title="Mesma marca e modelo, com o ano alargado ou sem filtrar combustível, caixa ou potência.">critérios alargados</span>
            )}
        </span>
    );
};

// ── Recomendações (motor novo) ───────────────────────────────────────────────

const RecommendationItem = ({ r }: { r: Recommendation }) => {
    const lvl = LEVEL_UI[r.level];
    const carTitle = typeof r.evidence?.car_title === "string" ? (r.evidence.car_title as string) : null;
    const carId = typeof r.evidence?.car_id === "number" ? (r.evidence.car_id as number) : null;
    const button = isExternal(r.action.url) ? (
        <a href={r.action.url} target="_blank" rel="noopener noreferrer" className="btn btn-sm btn-soft-primary flex-shrink-0">
            {r.action.label}<i className="ri-external-link-line ms-1" />
        </a>
    ) : (
        <Link to={r.action.url} className="btn btn-sm btn-soft-primary flex-shrink-0">{r.action.label}</Link>
    );
    return (
        <div
            className="d-flex flex-wrap align-items-start justify-content-between gap-3 rounded p-3"
            style={{ border: "1px solid var(--vz-border-color)", borderLeft: `4px solid var(--vz-${lvl.color})`, background: "var(--vz-tertiary-bg)" }}
        >
            <div style={{ minWidth: 0, flex: "1 1 320px" }}>
                <div className="d-flex align-items-center flex-wrap gap-2 mb-1">
                    <span className={`badge bg-${lvl.color}-subtle text-${lvl.color}`}>{lvl.label}</span>
                    <span className="fw-semibold text-body">{r.title}</span>
                    {carTitle && carId !== null && (
                        <Link to={`/cars/${carId}`} className="text-muted fs-13">{carTitle}</Link>
                    )}
                </div>
                <p className="text-muted fs-13 mb-0">{r.why}</p>
            </div>
            {button}
        </div>
    );
};

// ── Funil por viatura ────────────────────────────────────────────────────────

const AdStatus = ({ s }: { s: FunnelRow["ad_status"] }) =>
    s.status === "active" ? <span className="badge bg-success-subtle text-success fw-normal">Ativo{s.active_ads > 1 ? ` (${s.active_ads})` : ""}</span>
        : s.status === "inactive" ? <span className="badge bg-light text-muted fw-normal">Sem anúncio ativo</span>
            : <span className="text-muted">—</span>;

const Cpl = ({ value, state }: { value: number | null; state: FunnelRow["cpl_state"] }) =>
    state === "ok" && value !== null ? <span>{eur2(value)}</span>
        : state === "spend_without_lead" ? <span className="badge bg-warning-subtle text-warning fw-normal" title="Houve investimento e nenhuma lead com origem paga no período.">gasto sem lead</span>
            : <span className="text-muted">—</span>;

/**
 * Cabeçalho ordenável do funil. A ordenação é pedida ao BACKEND (sobre todas as
 * viaturas, antes da paginação): ordenar por vistas dá o top real, não só o da
 * página. Primeiro clique: maior primeiro; segundo clique na mesma coluna: inverte.
 */
function SortableTh({ label, sortKey, sort, onSort }: {
    label: string;
    sortKey: FunnelSortKey;
    sort: { by: FunnelSortKey; direction: SortDirection };
    onSort: (key: FunnelSortKey) => void;
}) {
    const active = sort.by === sortKey;
    const icon = !active ? "ri-arrow-up-down-line opacity-50" : sort.direction === "desc" ? "ri-arrow-down-line" : "ri-arrow-up-line";
    return (
        <th className="text-end" aria-sort={active ? (sort.direction === "desc" ? "descending" : "ascending") : "none"}>
            <button
                type="button"
                className={`btn btn-link btn-sm p-0 text-decoration-none fs-13 ${active ? "text-primary fw-semibold" : "text-muted"}`}
                onClick={() => onSort(sortKey)}
                title={`Ordenar por ${label.toLowerCase()}`}
            >
                {label} <i className={icon} />
            </button>
        </th>
    );
}

function FunnelCard({ companyId }: { companyId: number }) {
    const [days, setDays] = useState<14 | 30>(30);
    const [page, setPage] = useState(1);
    // Por omissão: dias em stock, maior primeiro (a mesma do backend).
    const [sort, setSort] = useState<{ by: FunnelSortKey; direction: SortDirection }>({ by: "days_in_stock", direction: "desc" });
    const [data, setData] = useState<AutomotiveFunnel | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    useEffect(() => {
        let alive = true;
        setLoading(true);
        setError(false);
        getAutomotiveHubFunnel(companyId, days, page, 10, sort.by, sort.direction)
            .then((r: any) => { if (alive) setData(r?.data ?? null); })
            .catch(() => { if (alive) setError(true); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, days, page, sort]);

    const onSort = (key: FunnelSortKey) => {
        setSort((s) => (s.by === key ? { by: key, direction: s.direction === "desc" ? "asc" : "desc" } : { by: key, direction: "desc" }));
        setPage(1);
    };

    const t = data?.totals;

    return (
        <Card className="mb-3">
            <CardBody>
                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <div>
                        <h6 className="text-uppercase text-muted fs-12 mb-1">Funil por viatura</h6>
                        <p className="text-muted fs-12 mb-0">
                            Vistas, contactos diretos (WhatsApp, chamada, telefone), leads e venda nos últimos {days} dias, com o investimento Meta atribuído a cada viatura.
                        </p>
                    </div>
                    <ButtonGroup size="sm" aria-label="Período do funil">
                        {([14, 30] as const).map((d) => (
                            <Button key={d} color="primary" outline={days !== d} onClick={() => { setDays(d); setPage(1); }} disabled={loading && days === d}>{d} dias</Button>
                        ))}
                    </ButtonGroup>
                </div>

                {loading && !data ? (
                    <div className="py-3 text-center"><Spinner size="sm" color="primary" /></div>
                ) : error || !data ? (
                    <p className="text-muted mb-0 fs-13">Não foi possível carregar o funil. Tente novamente dentro de momentos.</p>
                ) : data.rows.length === 0 ? (
                    <p className="text-muted mb-0 fs-13">Sem viaturas em stock nem vendidas neste período.</p>
                ) : (
                    <div className="table-responsive" style={{ opacity: loading ? 0.6 : 1 }}>
                        <Table className="table-sm align-middle mb-0 fs-13">
                            <thead className="text-muted">
                                <tr>
                                    <th>Viatura</th>
                                    <SortableTh label="Dias em stock" sortKey="days_in_stock" sort={sort} onSort={onSort} />
                                    <th>Preço face ao mercado</th>
                                    <SortableTh label="Vistas" sortKey="views" sort={sort} onSort={onSort} />
                                    <SortableTh label="Contactos" sortKey="contacts" sort={sort} onSort={onSort} />
                                    <SortableTh label="Leads" sortKey="leads" sort={sort} onSort={onSort} />
                                    <th>Venda</th>
                                    <th className="text-end">Investimento</th>
                                    <th className="text-end">CPL pago</th>
                                    <th>Anúncio</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.rows.map((r) => (
                                    <tr key={r.car_id}>
                                        <td style={{ minWidth: 180 }}><Link to={`/cars/${r.car_id}`} className="text-body fw-medium">{r.car_title || `Viatura n.º ${r.car_id}`}</Link></td>
                                        <td className="text-end">{int(r.days_in_stock)}</td>
                                        <td><PriceBadge price={r.price} /></td>
                                        <td className="text-end">{int(r.views)}</td>
                                        <td className="text-end">{int(r.contacts)}</td>
                                        <td className="text-end">{int(r.leads)}{r.paid_leads > 0 && <span className="text-muted fs-11"> ({int(r.paid_leads)} pagas)</span>}</td>
                                        <td>{r.sold ? <span className="badge bg-success-subtle text-success fw-normal">Vendida {dm(r.sold_at)}</span> : <span className="text-muted">—</span>}</td>
                                        <td className="text-end">{r.paid_spend > 0 ? eur0(r.paid_spend) : <span className="text-muted">—</span>}</td>
                                        <td className="text-end"><Cpl value={r.cpl} state={r.cpl_state} /></td>
                                        <td><AdStatus s={r.ad_status} /></td>
                                    </tr>
                                ))}
                            </tbody>
                            {t && (
                                <tfoot className="fw-semibold">
                                    <tr>
                                        <td>Total de todas as páginas ({int(t.cars)} viaturas)</td>
                                        <td />
                                        <td />
                                        <td className="text-end">{int(t.views)}</td>
                                        <td className="text-end">{int(t.contacts)}</td>
                                        <td className="text-end">{int(t.leads)}</td>
                                        <td>{int(t.sales)} {t.sales === 1 ? "venda" : "vendas"}</td>
                                        <td className="text-end">{t.paid_spend > 0 ? eur0(t.paid_spend) : "—"}</td>
                                        <td className="text-end"><Cpl value={t.cpl} state={t.cpl_state} /></td>
                                        <td />
                                    </tr>
                                </tfoot>
                            )}
                        </Table>
                    </div>
                )}
                {data && data.pagination.last_page > 1 && (
                    <div className="mt-3">
                        <Pagination
                            currentPage={data.pagination.current_page}
                            lastPage={data.pagination.last_page}
                            total={data.pagination.total}
                            perPage={data.pagination.per_page}
                            from={data.pagination.from}
                            to={data.pagination.to}
                            onPageChange={(p) => setPage(p)}
                        />
                    </div>
                )}
            </CardBody>
        </Card>
    );
}

// ── Separador "Stock" ────────────────────────────────────────────────────────

export default function AutomotiveHubStock({ companyId, onHighCount }: {
    companyId: number;
    /** O contador do separador passa a ler a MESMA resposta que a lista. */
    onHighCount?: (n: number) => void;
}) {
    const [hub, setHub] = useState<AutomotiveHub | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [showWarnings, setShowWarnings] = useState(false);

    useEffect(() => {
        let alive = true;
        setLoading(true);
        getAutomotiveHub(companyId)
            .then((r: any) => {
                if (!alive) return;
                const d: AutomotiveHub | null = r?.data ?? null;
                setHub(d);
                if (d) onHighCount?.(d.recommendations.high_count);
            })
            .catch(() => { if (alive) setError(true); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, onHighCount]);

    if (loading && !hub) return <div className="text-center py-4"><Spinner color="primary" size="sm" /></div>;
    if (error || !hub) {
        return <Alert color="light" className="fs-13">Não foi possível carregar o resumo do stock. Tente novamente dentro de momentos.</Alert>;
    }

    const s = hub.summary;
    const p = s.price_position;
    const m = s.meta_spend;
    const recs = hub.recommendations;
    const warnings = hub.warnings.invalid_tags;
    const thresholds = s.stuck_capital.thresholds;

    return (
        <section className="mb-4">
            {/* ── RESUMO ── */}
            <Row className="g-3 mb-3">
                <Col xs={12} sm={6} xl>
                    <MetricCard label="Viaturas em stock" icon="ri-car-line" color="primary" value={int(s.stock.total_cars)}
                        help="Viaturas ativas, reservadas ou disponíveis em breve. Inclui as retomas (viaturas recebidas em troca), que ainda não estão à venda como stock próprio.">
                        <div className="fs-12 text-muted">
                            {s.stock.trade_ins > 0
                                ? <>Inclui {int(s.stock.trade_ins)} {s.stock.trade_ins === 1 ? "retoma" : "retomas"} · {int(s.stock.own_stock)} de stock próprio</>
                                : <>Todas de stock próprio</>}
                        </div>
                        {s.stock.total_cars > 0 && (
                            <div className="fs-12 text-muted">Preço médio {eur0(s.stock.avg_price)}</div>
                        )}
                    </MetricCard>
                </Col>
                <Col xs={12} sm={6} xl>
                    <MetricCard label="Dias médios em stock" icon="ri-time-line" color="info" value={`${int(s.stock.avg_days_in_stock)} dias`}
                        help="A média de dias desde a entrada em stock das viaturas em stock (incluindo retomas). Cada viatura conta da data de entrada até hoje.">
                        <div className="fs-12 text-muted">Desde a entrada em stock até hoje</div>
                    </MetricCard>
                </Col>
                <Col xs={12} sm={6} xl>
                    <MetricCard label="Capital parado" icon="ri-money-euro-circle-line" color="warning" value={eur0(s.stuck_capital.amount)}
                        help={`O preço das viaturas de stock próprio que já passaram o limiar de dias do seu tipo: carros ${thresholds.car ?? 45} dias, motos ${thresholds.motorcycle ?? 45}, autocaravanas ${thresholds.motorhome ?? 120} e caravanas ${thresholds.caravan ?? 120}.`}>
                        <div className="fs-12 text-muted">
                            {int(s.stuck_capital.cars)} {s.stuck_capital.cars === 1 ? "viatura acima do limiar" : "viaturas acima do limiar"} · de {eur0(s.stuck_capital.total_capital)} em stock próprio
                        </div>
                    </MetricCard>
                </Col>
                <Col xs={12} sm={6} xl>
                    <MetricCard label="Acima do mercado" icon="ri-scales-3-line" color="danger"
                        value={p.above_market_pct !== null ? pct1(p.above_market_pct) : undefined}
                        unavailable={p.above_market_pct === null ? "Ainda não há comparações exatas com confiança média ou alta." : undefined}
                        help={`A percentagem das viaturas com comparação EXATA (anúncios do mesmo modelo) e confiança média ou alta que estão acima da mediana do mercado. ${APPROX_HELP}`}>
                        <div className="fs-12 text-muted">
                            {int(p.above_market_cars)} de {int(p.eligible_cars)} com comparação exata
                            {p.approximate_cars > 0 && <> · <span title={APPROX_HELP}>{int(p.approximate_cars)} com comparação aproximada</span></>}
                            {p.low_confidence_cars > 0 && <> · {int(p.low_confidence_cars)} com poucos anúncios comparáveis</>}
                        </div>
                    </MetricCard>
                </Col>
                <Col xs={12} sm={6} xl>
                    <MetricCard label={`Investimento Meta (${m.window_days} dias)`} icon="ri-facebook-circle-line" color="secondary" value={eur0(m.by_car)}
                        help="O investimento em anúncios Meta dos últimos 30 dias atribuído a viaturas (pela etiqueta [id:N] no nome do anúncio ou pelo mapeamento manual de campanhas). Por baixo, os anúncios de stock geral (sem etiqueta) e os que têm a etiqueta inválida. Valores sem IVA, como a Meta os reporta.">
                        <div className="fs-12 text-muted">
                            Por viatura
                            {m.ad_level_available
                                ? <>{" · "}Stock geral {eur0(m.general_stock ?? 0)}{(m.unattributed ?? 0) > 0 && <> · <span className="text-warning">Por atribuir {eur0(m.unattributed ?? 0)}</span></>}</>
                                : <> · a divisão por anúncio aparece quando a ingestão por anúncio da Meta estiver ativa</>}
                        </div>
                    </MetricCard>
                </Col>
            </Row>

            {/* ── AVISOS DE QUALIDADE (discretos, só se existirem) ── */}
            {warnings.count > 0 && (
                <Alert color="light" className="fs-13 py-2 mb-3 border">
                    <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <span>
                            <i className="ri-price-tag-3-line text-warning me-1" />
                            {int(warnings.count)} {warnings.count === 1 ? "anúncio com etiqueta inválida" : "anúncios com etiqueta inválida"}: o gasto desses anúncios não está atribuído a nenhuma viatura.
                        </span>
                        <span className="d-flex gap-2">
                            <button type="button" className="btn btn-link btn-sm p-0" onClick={() => setShowWarnings((v) => !v)} aria-expanded={showWarnings}>
                                {showWarnings ? "Esconder" : "Ver anúncios"}
                            </button>
                            <Link to="/meta-ads" className="btn btn-link btn-sm p-0">Meta / Anúncios</Link>
                        </span>
                    </div>
                    {showWarnings && (
                        <ul className="mb-0 mt-2 ps-3 fs-12 text-muted">
                            {warnings.items.map((w) => (
                                <li key={w.ad_id}>
                                    «{w.ad_name ?? w.ad_id}»: {w.invalid_ids.length > 0 ? `etiqueta com ${w.invalid_ids.join(", ")} (não é uma viatura desta empresa)` : "etiqueta vazia"}
                                    {w.spend_recent > 0 && <> · {eur0(w.spend_recent)} nos últimos 90 dias</>}
                                </li>
                            ))}
                        </ul>
                    )}
                </Alert>
            )}

            {/* ── RECOMENDAÇÕES (o mesmo motor do contador do separador) ── */}
            <Card className="mb-3">
                <CardBody>
                    <div className="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-3">
                        <h6 className="text-uppercase text-muted fs-12 mb-0"><i className="ri-lightbulb-flash-line me-1 text-warning" />Recomendações</h6>
                        {recs.total > recs.recommendations.length && (
                            <span className="text-muted fs-12">As {recs.recommendations.length} mais prioritárias de {int(recs.total)}, uma por viatura.</span>
                        )}
                    </div>
                    {recs.recommendations.length === 0 ? (
                        <p className="text-muted mb-0 fs-13">Sem recomendações neste momento.</p>
                    ) : (
                        <div className="vstack gap-2">
                            {recs.recommendations.map((r, i) => <RecommendationItem key={`${r.rule_key}-${i}`} r={r} />)}
                        </div>
                    )}
                    {recs.notices.map((n) => (
                        <p key={n.code} className="text-muted fs-12 mb-0 mt-2"><i className="ri-information-line me-1" />{n.message}</p>
                    ))}
                </CardBody>
            </Card>

            {/* ── FUNIL POR VIATURA ── */}
            <FunnelCard companyId={companyId} />

        </section>
    );
}
