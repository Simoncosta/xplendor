import { useCallback, useEffect, useState } from "react";
import { useModules } from "contexts/ModulesContext";
import classnames from "classnames";
import { Link, useSearchParams } from "react-router-dom";
import { Card, CardBody, Container, Row, Col, Nav, NavItem, NavLink, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import InfoTip from "Components/Common/InfoTip";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import ReasonButton from "Components/Common/ReasonButton";
import ConfirmModal from "Components/Common/ConfirmModal";
import { getPingwinDashboard, getRestaurantSignals, queuePingwinSync } from "helpers/laravel_helper";
import { PingwinDashboard as PingwinDashboardData, PingwinMoneyPair, PingwinOccupancyPeriod, RestaurantSignalsData } from "common/models/pingwin.model";
import DashboardSectionHeader from "./components/DashboardSectionHeader";
import MonthlyBillingChart from "./components/MonthlyBillingChart";
import RestaurantMarketingBlock from "./components/RestaurantMarketingBlock";
import { useRecommendations } from "./components/RecommendationsCard";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import HeatmapCard from "pages/Restauracao/HeatmapCard";
import BussolaDashboardSummary from "pages/Marketing/bussola/BussolaDashboardSummary";

/**
 * XPLENDOR — Dashboard de restauração (empresas com o módulo pingwin), com dados
 * reais do PingWin. 3 cards (Anual / Mensal / Diário) com OS DOIS valores
 * (faturado c/IVA + líquido) e comparações (vs ano passado / mesmo dia da
 * semana), que mostram "n.d." quando o período anterior não está todo sincronizado
 * (portão de honestidade). Tabela de lojas com faturação + última sincronização.
 * O conteúdo (PingwinDashboardContent) é reutilizado no painel principal.
 */

const yesterdayIso = () => {
    const d = new Date();
    d.setDate(d.getDate() - 1);
    return d.toISOString().slice(0, 10);
};

// Hoje (limite máximo do seletor): permite escolher ATÉ hoje (inclusive), nunca o futuro.
const todayIso = () => new Date().toISOString().slice(0, 10);

// Valores guardados em CÊNTIMOS → dividir por 100 e mostrar 2 casas (pt-PT: €1.694,02).
const euro = (cents: number) =>
    (Number(cents || 0) / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR", minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Valor indisponível (sem dados comparáveis / sem sincronização): "n.d.", nunca 0 falso. */
const NA = "n.d.";

/** % de comparação: "n.d." quando null (portão de honestidade); seta/cor conforme sinal. */
const DeltaPct = ({ pct }: { pct: number | null }) => {
    if (pct === null || pct === undefined) {
        return <span className="text-muted" title="Sem dados comparáveis completos">{NA}</span>;
    }
    const up = pct >= 0;
    return (
        <span className={up ? "text-success" : "text-danger"}>
            <i className={up ? "ri-arrow-up-line" : "ri-arrow-down-line"} /> {Math.abs(pct).toLocaleString("pt-PT")}%
        </span>
    );
};

// Contexto do período (para o utilizador perceber que Anual/Mensal podem coincidir
// quando só há dados deste mês) — em pt-PT.
const monthLabel = (ym?: string) => {
    if (!ym) return "";
    const [y, m] = ym.split("-").map(Number);
    return new Date(y, (m || 1) - 1, 1).toLocaleDateString("pt-PT", { month: "long", year: "numeric" });
};
const dayLabel = (d?: string) =>
    d ? new Date(d + "T00:00:00").toLocaleDateString("pt-PT", { day: "2-digit", month: "short", year: "numeric" }) : "";

/** Card de período: faturado c/IVA + líquido; contexto do período + cor de acento; comparação opcional. */
const PeriodCard = ({ label, subtitle, icon, accent, values, compareLabel, deltaInvoiced, deltaNet }: {
    label: string;
    subtitle?: string;
    icon: string;
    accent: "primary" | "info" | "success";
    values: PingwinMoneyPair;
    compareLabel?: string;
    deltaInvoiced?: number | null;
    deltaNet?: number | null;
}) => (
    <Col md={4}>
        <Card className="h-100 mb-0" style={{ borderTop: `3px solid var(--vz-${accent})` }}>
            <CardBody>
                <div className="d-flex align-items-start justify-content-between mb-2">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-0" style={{ letterSpacing: "0.08em" }}>{label}</p>
                        {/* Só a primeira letra em maiúscula ("Ano de 2026 (até à data)"), não cada palavra. */}
                        {subtitle && <p className="text-muted fs-11 mb-0">{subtitle.charAt(0).toUpperCase() + subtitle.slice(1)}</p>}
                    </div>
                    <i className={`${icon} fs-4 text-${accent}`} />
                </div>
                <div className="mb-1">
                    <span className="text-muted fs-12">Faturado c/IVA</span>
                    <h4 className={`mb-0 fw-semibold text-${accent}`}>{euro(values.invoiced_cents)}</h4>
                </div>
                <div className="mb-0">
                    <span className="text-muted fs-12">Líquido</span>
                    <h5 className="mb-0 fw-semibold text-muted">{euro(values.net_cents)}</h5>
                </div>
                {compareLabel && (
                    <div className="mt-2 pt-2 fs-12" style={{ borderTop: "1px dashed var(--vz-border-color)" }}>
                        <div className="d-flex justify-content-between">
                            <span className="text-muted">{compareLabel} (faturado)</span>
                            <DeltaPct pct={deltaInvoiced ?? null} />
                        </div>
                        <div className="d-flex justify-content-between">
                            <span className="text-muted">{compareLabel} (líquido)</span>
                            <DeltaPct pct={deltaNet ?? null} />
                        </div>
                    </div>
                )}
            </CardBody>
        </Card>
    </Col>
);

/** Valor em cêntimos → €X,XX, ou "n.d." quando null (sem dados / flag desligada). */
const euroOrNa = (cents: number | null | undefined) =>
    cents === null || cents === undefined ? NA : euro(cents);

/** Card do Ticket Médio (faturação PingWin ÷ pessoas CoverManager) — 3 períodos. */
const AvgTicketCard = ({ avg, enabled }: { avg?: { annual: number | null; monthly: number | null; daily: number | null }; enabled: boolean }) => (
    <Col md={6}>
        <Card className="h-100 mb-0" style={{ borderTop: "3px solid var(--vz-warning)" }}>
            <CardBody>
                <div className="d-flex align-items-start justify-content-between mb-2">
                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-0 d-flex align-items-center gap-1" style={{ letterSpacing: "0.08em" }}>
                        Ticket Médio <InfoTip text="Faturação ÷ pessoas que reservaram. Requer vendas e reservas sincronizadas." label="Sobre o ticket médio" />
                    </p>
                    <i className="ri-price-tag-3-line fs-4 text-warning" />
                </div>
                {!enabled ? (
                    <>
                        <h4 className="mb-1 fw-semibold text-muted">{NA}</h4>
                        <p className="text-muted fs-12 mb-0">Cálculo com o CoverManager desligado (Restauração › Lojas).</p>
                    </>
                ) : (
                    <div className="row g-0 text-center">
                        {([["Anual", avg?.annual], ["Mensal", avg?.monthly], ["Diário", avg?.daily]] as const).map(([lbl, val], i) => (
                            <div className="col-4" key={lbl} style={{ borderLeft: i > 0 ? "1px solid var(--vz-border-color)" : "none" }}>
                                <p className="text-muted fs-11 mb-1">{lbl}</p>
                                <h5 className="mb-0 fw-semibold text-warning">{euroOrNa(val)}</h5>
                            </div>
                        ))}
                    </div>
                )}
            </CardBody>
        </Card>
    </Col>
);

const num = (n: number) => Number(n || 0).toLocaleString("pt-PT");

/** Card da Lotação — TOTAL de pessoas que reservaram (todas as lojas), por período. */
const OccupancyCard = ({ occ }: { occ?: { annual: PingwinOccupancyPeriod; monthly: PingwinOccupancyPeriod; daily: PingwinOccupancyPeriod } }) => (
    <Col md={6}>
        <Card className="h-100 mb-0" style={{ borderTop: "3px solid var(--vz-info)" }}>
            <CardBody>
                <div className="d-flex align-items-start justify-content-between mb-2">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-0 d-flex align-items-center gap-1" style={{ letterSpacing: "0.08em" }}>
                            Pessoas (reservas) <InfoTip text="Nº de pessoas que reservaram (não é média). Requer reservas sincronizadas (CoverManager)." label="Sobre as pessoas" />
                        </p>
                        <p className="text-muted fs-11 mb-0">Total de todas as lojas</p>
                    </div>
                    <i className="ri-group-line fs-4 text-info" />
                </div>
                <div className="row g-0 text-center">
                    {([["Anual", occ?.annual], ["Mensal", occ?.monthly], ["Diário", occ?.daily]] as const).map(([lbl, p], i) => (
                        <div className="col-4" key={lbl} style={{ borderLeft: i > 0 ? "1px solid var(--vz-border-color)" : "none" }}>
                            <p className="text-muted fs-11 mb-1">{lbl}</p>
                            {/* has_data false → "n.d." (sem reservas sincronizadas), não 0 falso. */}
                            <h5 className="mb-1 fw-semibold text-info">{p?.has_data ? num(p.total) : NA}</h5>
                            <p className="text-muted fs-11 mb-0">
                                {p?.has_data ? <>{num(p.lunch)} almoço · {num(p.dinner)} jantar</> : "sem reservas"}
                            </p>
                        </div>
                    ))}
                </div>
            </CardBody>
        </Card>
    </Col>
);

const fmtDateTime = (d: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "Nunca";

/** Célula de um período por loja: faturado c/IVA (cor do tema) + líquido + lotação (pessoas). */
const PeriodCell = ({ pair, occ, color }: { pair: PingwinMoneyPair; occ?: PingwinOccupancyPeriod; color: string }) => (
    <div className="text-end">
        <div className={`fw-semibold ${color}`}>{euro(pair?.invoiced_cents ?? 0)}</div>
        <div className="text-muted fs-11">{euro(pair?.net_cents ?? 0)} líq.</div>
        <div className="text-muted fs-11">{occ?.has_data ? `${num(occ.total)} pessoas` : "sem reservas"}</div>
    </div>
);

/** Separador "Vendas": faturação por período, ticket médio, lotação, gráfico e lojas, com a data e o "atualizar". */
function RestaurantSalesTab({ companyId }: { companyId: number }) {
    const [date, setDate] = useState<string>(yesterdayIso());
    const [data, setData] = useState<PingwinDashboardData | null>(null);
    const [loading, setLoading] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [queuing, setQueuing] = useState(false);

    const fetchDashboard = useCallback(async (d?: string) => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinDashboard(companyId, d);
            setData(res?.data ?? null);
            // Alinha o seletor de data com a data efetiva (último dia sincronizado).
            if (res?.data?.date && !d) setDate(res.data.date);
        } catch {
            setData(null);
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => { fetchDashboard(); }, [fetchDashboard]);

    const confirmRefresh = async () => {
        if (!companyId) return;
        setQueuing(true);
        try {
            await queuePingwinSync(companyId, date);
            toast.info("A atualizar. Será notificado no sino quando os dados estiverem prontos.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível agendar a atualização.");
        } finally {
            setQueuing(false);
            setConfirmOpen(false);
        }
    };

    const annual = data?.annual;
    const monthly = data?.monthly;
    const daily = data?.daily;

    type Loc = PingwinDashboardData["locations"][number];
    const storeCols = useDataColumns<Loc>("dashboard.restauracao.lojas", [
        {
            id: "store", header: "Loja", value: (loc) => loc.display_name, hideable: false, mobile: "title",
            cell: (loc) => (
                <div className="d-flex align-items-center gap-2">
                    <span className="flex-shrink-0 rounded-circle bg-primary-subtle d-inline-flex align-items-center justify-content-center" style={{ width: 28, height: 28 }}>
                        <i className="ri-store-2-line text-primary fs-14" />
                    </span>
                    <span className="fw-medium">{loc.display_name}</span>
                </div>
            ),
        },
        { id: "annual", header: "Anual", align: "end", value: (loc) => loc.annual?.invoiced_cents ?? 0, cell: (loc) => <PeriodCell pair={loc.annual} occ={loc.occupancy?.annual} color="text-primary" /> },
        { id: "monthly", header: "Mensal", align: "end", value: (loc) => loc.monthly?.invoiced_cents ?? 0, cell: (loc) => <PeriodCell pair={loc.monthly} occ={loc.occupancy?.monthly} color="text-info" /> },
        { id: "daily", header: "Diário", align: "end", value: (loc) => loc.daily?.invoiced_cents ?? 0, cell: (loc) => <PeriodCell pair={loc.daily} occ={loc.occupancy?.daily} color="text-success" /> },
        { id: "synced", header: "Sincronização", align: "end", value: (loc) => loc.last_synced_at ?? undefined, cell: (loc) => <span className="text-muted fs-12">{fmtDateTime(loc.last_synced_at)}</span>, nowrap: true },
    ] as DTColumn<Loc>[]);

    return (
        <>
            <ConfirmModal
                isOpen={confirmOpen}
                title="Buscar dados de vendas"
                message={`Pretende buscar os dados de vendas atualizados de ${dayLabel(date)}? A atualização decorre em segundo plano e pode continuar a trabalhar.`}
                confirmText="Buscar"
                cancelText="Cancelar"
                variant="default"
                loading={queuing}
                onCancel={() => setConfirmOpen(false)}
                onConfirm={() => { void confirmRefresh(); }}
            />

            {/* Descrição + controlos do separador (data + atualizar), junto do conteúdo
                que controlam. Sem título: o separador "Vendas" já o diz. */}
            <DashboardSectionHeader controlsStart subtitle="Faturação, ticket médio e lotação, segundo o sistema de vendas e as reservas.">
                <input
                    id="pingwin-sales-date"
                    type="date"
                    className="form-control form-control-sm w-auto"
                    aria-label="Data"
                    value={date}
                    max={todayIso()}
                    onChange={(e) => { setDate(e.target.value); fetchDashboard(e.target.value); }}
                    style={{ minWidth: 170 }}
                />
                <ReasonButton size="sm" color="outline-primary" onClick={() => setConfirmOpen(true)} disabled={queuing}
                    reason={!companyId ? "Escolha primeiro a empresa." : !date ? "Indique a data." : null}>
                    {queuing ? <><Spinner size="sm" className="me-1" /> A atualizar</> : <><i className="ri-refresh-line me-1" /> <span className="d-none d-sm-inline">Buscar dados atualizados</span><span className="d-sm-none">Atualizar</span></>}
                </ReasonButton>
            </DashboardSectionHeader>

            {/* 3 cards de faturação por período (contexto do período no subtítulo) */}
            <Row className="g-3 mb-3">
                <PeriodCard
                    label="Anual"
                    subtitle={annual?.year ? `ano de ${annual.year} (até à data)` : undefined}
                    icon="ri-calendar-2-line"
                    accent="primary"
                    values={annual ?? { invoiced_cents: 0, net_cents: 0 }}
                    compareLabel="vs ano passado"
                    deltaInvoiced={annual?.delta_pct_invoiced ?? null}
                    deltaNet={annual?.delta_pct_net ?? null}
                />
                <PeriodCard
                    label="Mensal"
                    subtitle={monthLabel(monthly?.month)}
                    icon="ri-calendar-line"
                    accent="info"
                    values={monthly ?? { invoiced_cents: 0, net_cents: 0 }}
                />
                <PeriodCard
                    label="Diário"
                    subtitle={dayLabel(daily?.date)}
                    icon="ri-calendar-check-line"
                    accent="success"
                    values={daily ?? { invoiced_cents: 0, net_cents: 0 }}
                    compareLabel="vs mesmo dia da semana anterior"
                    deltaInvoiced={daily?.delta_pct_invoiced ?? null}
                    deltaNet={daily?.delta_pct_net ?? null}
                />
            </Row>

            {/* Ticket Médio + Lotação (ambos reais, do CoverManager). */}
            <Row className="g-3 mb-3">
                <AvgTicketCard avg={data?.avg_ticket} enabled={data?.avg_ticket_enabled ?? true} />
                <OccupancyCard occ={data?.occupancy} />
            </Row>

            {/* Gráfico de faturação mensal por restaurante (acima da tabela). */}
            <Row className="g-3 mb-3">
                <MonthlyBillingChart />
            </Row>

            {/* F2: mapa da semana (dia × hora), só com o interruptor das vendas por artigo ligado. */}
            <Row className="g-3 mb-3">
                <HeatmapCard companyId={companyId} />
            </Row>

            {/* Lojas: PageCard + DataTable (UI-2d). pb-5 mb-5: folga até ao rodapé. */}
            <Row className="g-3 pb-5 mb-5">
                <Col xs={12}>
                    <PageCard className="mb-0" title="Lojas" info="Por período: faturado com IVA, líquido e pessoas." loading={loading && (data?.locations?.length ?? 0) > 0} actions={storeCols.selector}>
                        <DataTable
                            columns={storeCols}
                            data={data?.locations ?? []}
                            rowKey={(loc) => loc.id}
                            loading={loading && !data}
                            paginate={false}
                            caption="Faturação por loja"
                            empty={{ message: <>Sem dados. Escolha uma data e use <strong>“Buscar dados atualizados”</strong>.</> }}
                        />
                    </PageCard>
                </Col>
            </Row>
        </>
    );
}

// ── Separadores "Vendas" | "Marketing e resultados" ─────────────────────────

type RestaurantTab = "vendas" | "marketing";

const RESTAURANT_TABS: { key: RestaurantTab; label: string; icon: string }[] = [
    { key: "vendas", label: "Vendas", icon: "ri-money-euro-circle-line" },
    { key: "marketing", label: "Marketing e resultados", icon: "ri-line-chart-line" },
];

/** O separador vem do URL (?tab=vendas | ?tab=marketing); por defeito, Vendas. */
const tabFromSearch = (value: string | null): RestaurantTab => (value === "marketing" ? "marketing" : "vendas");

/**
 * Nav de separadores no estilo "Border Top Nav" do template (nav-border-top): o
 * separador ativo tem um traço por cima. Cada separador é um
 * link (?tab=…), por isso recarregar ou partilhar o URL abre no mesmo sítio.
 * Em mobile, scroll horizontal se não couber.
 */
function RestaurantTabsNav({ active, highCount, signalsCount = 0, tabs = RESTAURANT_TABS }: { active: RestaurantTab; highCount: number; signalsCount?: number; tabs?: typeof RESTAURANT_TABS }) {
    return (
        <div style={{ overflowX: "auto" }} className="mb-3">
            <Nav tabs className="nav-border-top nav-border-top-primary flex-nowrap" style={{ minWidth: "max-content" }}>
                {tabs.map((t) => {
                    const isActive = t.key === active;
                    return (
                        <NavItem key={t.key}>
                            <NavLink
                                tag={Link}
                                to={`?tab=${t.key}`}
                                replace
                                active={isActive}
                                aria-current={isActive ? "page" : undefined}
                                className={classnames("d-inline-flex align-items-center gap-2 text-nowrap", { "text-body": !isActive })}
                            >
                                <i className={t.icon} />
                                {t.label}
                                {/* Contador das recomendações de prioridade alta, para não passarem despercebidas. */}
                                {t.key === "marketing" && highCount > 0 && (
                                    <span
                                        className="badge rounded-pill bg-danger fs-11"
                                        title={`${highCount} ${highCount === 1 ? "recomendação" : "recomendações"} de prioridade alta`}
                                        aria-label={`${highCount} ${highCount === 1 ? "recomendação" : "recomendações"} de prioridade alta`}
                                    >
                                        {highCount}
                                    </span>
                                )}
                                {/* F3: sugestões de "O que publicar e quando". */}
                                {t.key === "marketing" && signalsCount > 0 && (
                                    <span
                                        className="badge rounded-pill bg-primary-subtle text-primary fs-11"
                                        title={`${signalsCount} ${signalsCount === 1 ? "sugestão" : "sugestões"} de publicação`}
                                        aria-label={`${signalsCount} ${signalsCount === 1 ? "sugestão" : "sugestões"} de publicação`}
                                    >
                                        {signalsCount}
                                    </span>
                                )}
                            </NavLink>
                        </NavItem>
                    );
                })}
            </Nav>
        </div>
    );
}

export function PingwinDashboardContent() {
    const companyId = useWorkingCompanyId();

    const [searchParams] = useSearchParams();
    // As vendas da restauração são restauracao.ver: sem ela (por exemplo, o perfil Marketing), só o
    // separador "Marketing e resultados".
    const { can, loading: accessLoading } = useModules();
    const salesAllowed = can("restauracao.ver");
    const tab = salesAllowed ? tabFromSearch(searchParams.get("tab")) : "marketing";
    const tabs = salesAllowed ? RESTAURANT_TABS : RESTAURANT_TABS.filter((t) => t.key !== "vendas");

    // Cada separador só monta (e só carrega os seus dados) quando é aberto pela
    // primeira vez; depois fica montado, escondido, para manter o mês/data escolhidos.
    const [opened, setOpened] = useState<Record<RestaurantTab, boolean>>({ vendas: tab === "vendas", marketing: tab === "marketing" });
    useEffect(() => {
        setOpened((o) => (o[tab] ? o : { ...o, [tab]: true }));
    }, [tab]);

    // Só as recomendações carregam logo (são leves): o contador do separador precisa
    // delas. Os dados de marketing (GA4, Meta) só carregam ao abrir o separador.
    const recommendations = useRecommendations(companyId, "restaurant", companyId > 0);
    const highCount = (recommendations.data?.recommendations ?? []).filter((r) => r.level === "high").length;

    // F3: as sugestões da Bússola (o contador do separador; o resumo carrega no separador).
    const [signals, setSignals] = useState<RestaurantSignalsData | null>(null);
    useEffect(() => {
        if (!companyId) return;
        let alive = true;
        getRestaurantSignals(companyId).then((r: any) => { if (alive) setSignals(r?.data ?? null); }).catch(() => { if (alive) setSignals(null); });
        return () => { alive = false; };
    }, [companyId]);

    // Enquanto as permissões carregam, nada que dependa delas aparece (ACL, F4).
    if (accessLoading) return null;

    return (
        <>
            <ToastContainer />
            <RestaurantTabsNav active={tab} highCount={highCount} signalsCount={signals?.enabled ? signals.suggestions.length : 0} tabs={tabs} />

            {opened.vendas && salesAllowed && (
                <div className={tab === "vendas" ? undefined : "d-none"}>
                    <RestaurantSalesTab companyId={companyId} />
                </div>
            )}
            {opened.marketing && companyId > 0 && (
                <div className={tab === "marketing" ? "pb-5 mb-5" : "d-none"}>
                    <RestaurantMarketingBlock companyId={companyId} recommendations={recommendations} />
                    {/* No fim, junto às Recomendações: as 3 jogadas da semana (também são recomendações). */}
                    <BussolaDashboardSummary companyId={companyId} />
                </div>
            )}
        </>
    );
}

/** Título de página único (padrão Velzon), comum ao /dashboard e ao /restauracao. */
export const RestaurantPageTitle = () => (
    <PageHeader title="Restauração" breadcrumbs={[{ label: "Painel", to: "/dashboard" }]} />
);

export default function PingwinDashboard() {
    document.title = "Restauração | Xplendor";
    return (
        <div className="page-content">
            <Container fluid>
                <RestaurantPageTitle />
                <PingwinDashboardContent />
            </Container>
        </div>
    );
}
