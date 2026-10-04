// React
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { createSelector } from 'reselect';
import { Link, useSearchParams } from 'react-router-dom';
import classnames from 'classnames';
// Redux
import { useDispatch, useSelector } from 'react-redux';
// Components
import { Container, Nav, NavItem, NavLink, Row } from 'reactstrap';
import AutomotiveMarketingBlock from './components/AutomotiveMarketingBlock';
import AutomotiveHubStock from './components/AutomotiveHubStock';
import { useRecommendations } from './components/RecommendationsCard';
import { countHighPriority } from './components/automotiveMarketingText';
import { getAnalyticsDashboard, getStockBreakdown, getSalesRevenue } from 'slices/dashboards/thunk';
import SubscriptionTrialBanner from './components/SubscriptionTrialBanner';
import SilentBuyerExecutiveCard from './components/SilentBuyerExecutiveCard';
import StockBreakdownCard from './components/StockBreakdownCard';
import SalesRevenueCard from './components/SalesRevenueCard';
import { useModules } from "contexts/ModulesContext";
import { PingwinDashboardContent, RestaurantPageTitle } from "./PingwinDashboard";
import RootDashboard from "./RootDashboard";
import type { SalesRevenueGranularity } from "../../types/api";

// Silent Buyer ESCONDIDO do dashboard (decisão de produto). Reversível: basta pôr
// true. O componente e a lógica de backend (analytics.silent_buyers) ficam intactos.
const SHOW_SILENT_BUYER = false;

// "Composição do stock" ESCONDIDA do dashboard (decisão de produto). Reversível:
// basta pôr true. O componente e o endpoint dashboard/stock-breakdown ficam.
const SHOW_STOCK_BREAKDOWN = false;

const selectDashboardState = (state: any) => state.Dashboard;
const selectDashboardViewModel = createSelector(
    [selectDashboardState],
    (dashboardState) => ({
        analytics: dashboardState.data.analytics,
        loading: dashboardState.loading.list,
        stockBreakdown: dashboardState.data.stockBreakdown,
        stockBreakdownLoading: dashboardState.loading.stockBreakdown,
        salesRevenue: dashboardState.data.salesRevenue,
        salesRevenueLoading: dashboardState.loading.salesRevenue,
    })
);

// ── Separadores do dashboard do automóvel ────────────────────────────────────

type CarTab = "stock" | "marketing";

const CAR_TABS: { key: CarTab; label: string; icon: string }[] = [
    { key: "stock", label: "Stock", icon: "ri-car-line" },
    { key: "marketing", label: "Marketing e resultados", icon: "ri-line-chart-line" },
];

/** O separador vem do URL (?tab=stock | ?tab=marketing); por defeito, Stock. */
const tabFromSearch = (value: string | null): CarTab => (value === "marketing" ? "marketing" : "stock");

/**
 * Nav de separadores no estilo "Border Top Nav" do template (nav-border-top), como
 * na restauração: cada separador é um link (?tab=…), por isso recarregar ou partilhar
 * o URL abre no mesmo sítio. Em mobile, scroll horizontal se não couber.
 */
function CarTabsNav({ active, highCount }: { active: CarTab; highCount: number }) {
    return (
        <div style={{ overflowX: "auto" }} className="mb-3">
            <Nav tabs className="nav-border-top nav-border-top-primary flex-nowrap" style={{ minWidth: "max-content" }}>
                {CAR_TABS.map((t) => {
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
                                {/* Recomendações de prioridade alta (uma por viatura), para não passarem despercebidas. */}
                                {t.key === "stock" && highCount > 0 && (
                                    <span
                                        className="badge rounded-pill bg-danger fs-11"
                                        title={`${highCount} ${highCount === 1 ? "recomendação" : "recomendações"} de prioridade alta`}
                                        aria-label={`${highCount} ${highCount === 1 ? "recomendação" : "recomendações"} de prioridade alta`}
                                    >
                                        {highCount}
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

/**
 * Separador "Stock": o hub do automóvel (resumo, recomendações do motor novo,
 * funil por viatura, avisos e atalhos), seguido da composição do stock e da
 * faturação por período. Carrega ao abrir. O antigo "Viaturas que merecem
 * atenção" (CarIssueEngine) saiu: as recomendações vêm do mesmo motor do contador.
 */
const CarStockTab = ({ companyId, onHighCount }: { companyId: number; onHighCount: (n: number) => void }) => {
    const dispatch: any = useDispatch();
    const {
        analytics,
        stockBreakdown, stockBreakdownLoading,
        salesRevenue, salesRevenueLoading,
    } = useSelector(selectDashboardViewModel);

    useEffect(() => {
        if (!companyId) return;
        // Visões 1+2 (2026-06-25) — composição do stock (escondida; só pede se voltar).
        if (SHOW_STOCK_BREAKDOWN) dispatch(getStockBreakdown({ companyId }));
        // O blob antigo do dashboard só faz falta ao Silent Buyer (escondido).
        if (SHOW_SILENT_BUYER) dispatch(getAnalyticsDashboard({ companyId }));
        // A faturação (V3) dispara o seu próprio pedido no SalesRevenueCard.
    }, [dispatch, companyId]);

    const handleSalesRangeChange = (range: { from: string; to: string; granularity: SalesRevenueGranularity }) => {
        if (!companyId) return;
        dispatch(getSalesRevenue({ companyId, from: range.from, to: range.to, granularity: range.granularity }));
    };

    return (
        <>
            <Row className="g-3 mb-3">
                <SubscriptionTrialBanner />
            </Row>
            <AutomotiveHubStock companyId={companyId} onHighCount={onHighCount} />
            {SHOW_STOCK_BREAKDOWN && (
                <Row className="g-3 mb-3">
                    <StockBreakdownCard data={stockBreakdown} loading={stockBreakdownLoading} />
                </Row>
            )}
            <Row className="g-3 mb-3">
                <SalesRevenueCard
                    data={salesRevenue}
                    loading={salesRevenueLoading}
                    onRangeChange={handleSalesRangeChange}
                />
            </Row>
            {SHOW_SILENT_BUYER && (analytics?.silent_buyers?.total_detected ?? 0) > 0 && (
                <Row className="g-3 mb-3">
                    <SilentBuyerExecutiveCard summary={analytics.silent_buyers} />
                </Row>
            )}
        </>
    );
};

/** Dashboard do automóvel com separadores: "Stock" (por defeito) e "Marketing e resultados". */
const CarDashboardTabs = () => {
    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

    const [searchParams] = useSearchParams();
    const tab = tabFromSearch(searchParams.get("tab"));

    // Cada separador só monta (e só carrega os seus dados) quando é aberto pela
    // primeira vez; depois fica montado, escondido, para manter o mês escolhido.
    const [opened, setOpened] = useState<Record<CarTab, boolean>>({ stock: tab === "stock", marketing: tab === "marketing" });
    useEffect(() => {
        setOpened((o) => (o[tab] ? o : { ...o, [tab]: true }));
    }, [tab]);

    // Contador do separador "Stock": enquanto o separador não abriu, vem do motor
    // (as recomendações são leves); quando abre, passa a ler a MESMA resposta do hub
    // que mostra a lista (high_count), com a mesma regra: uma por viatura.
    const recommendations = useRecommendations(companyId, "automotive", companyId > 0);
    const [hubHighCount, setHubHighCount] = useState<number | null>(null);
    const onHighCount = useCallback((n: number) => setHubHighCount(n), []);
    const highCount = hubHighCount ?? countHighPriority(recommendations.data?.recommendations ?? []);

    return (
        <>
            <CarTabsNav active={tab} highCount={highCount} />
            {opened.stock && (
                <div className={tab === "stock" ? undefined : "d-none"}>
                    <CarStockTab companyId={companyId} onHighCount={onHighCount} />
                </div>
            )}
            {opened.marketing && companyId > 0 && (
                <div className={tab === "marketing" ? "pb-5 mb-5" : "d-none"}>
                    <AutomotiveMarketingBlock companyId={companyId} />
                </div>
            )}
        </>
    );
};

const ClientDashboard = () => {
    document.title = "Dashboard | Xplendor";

    // O dashboard atual é de CARROS. Só o mostramos a empresas com módulos de
    // carros (stock/commercial_crm). Sem eles (ex.: restauração) → dashboard
    // vazio, sem crash (o dashboard do ramo é tarefa futura). `has` já trata
    // root/desconhecido como "vê tudo".
    const { has, isRoot, loading: modulesLoading } = useModules();
    const showCars = isRoot || has('stock') || has('commercial_crm');

    // O painel principal adapta-se ao RAMO. Empresa de restauração (módulo
    // pingwin, sem os de carros) vê o dashboard de restauração — já não fica em
    // branco. Sem nenhum ramo reconhecido → só o essencial.
    if (!modulesLoading && !showCars) {
        const showPingwin = isRoot || has('pingwin');
        return (
            <React.Fragment>
                <div className="page-content">
                    <Container fluid>
                        {/* Um só título de página no topo; depois as secções. */}
                        {showPingwin && <RestaurantPageTitle />}
                        <Row className="g-3 mb-3">
                            <SubscriptionTrialBanner />
                        </Row>
                        {showPingwin && <PingwinDashboardContent />}
                    </Container>
                </div>
            </React.Fragment>
        );
    }

    // Espera saber os módulos antes de carregar os dados de carros.
    if (modulesLoading) return null;

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>
                    <CarDashboardTabs />
                </Container>
            </div>
        </React.Fragment>
    );
};

/**
 * Encaixe do dashboard ROOT (sem hooks aqui → não parte as regras dos hooks dos filhos):
 * root DE VERDADE (role 'root' e NÃO em impersonation) → RootDashboard; caso contrário
 * (cliente normal, ou root a ver como cliente) → o dashboard do cliente, intacto.
 */
const Dashboard = () => {
    let isTrueRoot = false;
    try {
        const o = JSON.parse(sessionStorage.getItem("authUser") || "null");
        isTrueRoot = o?.role === "root" && !o?.impersonating;
    } catch { /* ignore */ }

    return isTrueRoot ? <RootDashboard /> : <ClientDashboard />;
};

export default Dashboard;
