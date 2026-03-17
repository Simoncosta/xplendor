// ─── useCarAnalyticsData ───────────────────────────────────────────────────────
// Centraliza toda a lógica de dados e helpers do CarAnalytics.
// O componente principal fica apenas com layout e navegação de tabs.

export const channelLabels: Record<string, string> = {
    paid: "Tráfego Pago",
    direct: "Direto",
    organic_social: "Social Orgânico",
    organic_search: "Pesquisa Orgânica",
    referral: "Referência",
    email: "Email",
    utm: "Campanha UTM",
};

export const channelColors: Record<string, string> = {
    paid: "#405189",
    direct: "#6c757d",
    organic_social: "#0ab39c",
    organic_search: "#198754",
    referral: "#f7b84b",
    email: "#6610f2",
    utm: "#fd7e14",
};

// ─── Formatters ───────────────────────────────────────────────────────────────

export const fmt = (n: number) =>
    new Intl.NumberFormat("pt-PT", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

export const fmtDate = (d: string) =>
    new Date(d).toLocaleDateString("pt-PT", { day: "2-digit", month: "short", year: "numeric" });

export const fmtTime = (d: string) =>
    new Date(d).toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" });

// ─── KPI helpers ──────────────────────────────────────────────────────────────

export const interestRateColor = (r: number) =>
    r >= 10 ? "text-success" : r >= 3 ? "text-warning" : "text-muted";

export const interestRateBadge = (r: number) =>
    r >= 10 ? "ri-arrow-up-line text-success" : r >= 3 ? "ri-subtract-line text-warning" : "ri-arrow-down-line text-muted";

export const buildKpiItems = (m: any) => [
    { id: 1, label: "Views", counter: m.views, icon: "ri-eye-line", iconClass: "text-primary", suffix: "", decimals: 0, valueClass: "text-body", badge: "" },
    { id: 2, label: "Views 24h", counter: m.views_24h, icon: "ri-time-line", iconClass: m.views_24h > 0 ? "text-info" : "text-muted", suffix: "", decimals: 0, valueClass: "text-body", badge: "" },
    { id: 3, label: "Views 7 dias", counter: m.views_7d, icon: "ri-calendar-line", iconClass: m.views_7d > 0 ? "text-secondary" : "text-muted", suffix: "", decimals: 0, valueClass: "text-body", badge: "" },
    { id: 4, label: "Interações", counter: m.interactions, icon: "ri-cursor-line", iconClass: m.interactions > 0 ? "text-success" : "text-muted", suffix: "", decimals: 0, valueClass: m.interactions > 0 ? "text-success" : "text-body", badge: "" },
    { id: 5, label: "Leads", counter: m.leads, icon: "ri-user-follow-line", iconClass: m.leads > 0 ? "text-success" : "text-muted", suffix: "", decimals: 0, valueClass: m.leads > 0 ? "text-success" : "text-body", badge: "" },
    { id: 6, label: "Taxa de Interesse", counter: m.interest_rate, icon: "ri-line-chart-line", iconClass: interestRateColor(m.interest_rate), suffix: "%", decimals: 1, valueClass: interestRateColor(m.interest_rate), badge: interestRateBadge(m.interest_rate) },
];

// ─── Traffic sources ──────────────────────────────────────────────────────────

export const buildTrafficSources = (rawSources: any[]) =>
    [...(rawSources || [])]
        .map((item: any) => ({
            key: item.channel,
            label: channelLabels[item.channel] || item.channel,
            total: Number(item.total || 0),
            color: channelColors[item.channel] || "#adb5bd",
        }))
        .sort((a, b) => b.total - a.total);

export const buildDonutOptions = (trafficSources: any[], totalTraffic: number): any => ({
    chart: { type: "donut", toolbar: { show: false } },
    labels: trafficSources.map((i) => i.label),
    colors: trafficSources.map((i) => i.color),
    legend: { show: false },
    dataLabels: { enabled: false },
    stroke: { width: 0 },
    plotOptions: {
        pie: {
            donut: {
                size: "72%",
                labels: {
                    show: true,
                    name: { show: true, offsetY: 18 },
                    value: { show: true, fontSize: "22px", fontWeight: 700, offsetY: -10, formatter: (v: any) => parseInt(v) },
                    total: { show: true, label: "Views", fontSize: "14px", fontWeight: 500, formatter: () => totalTraffic },
                },
            },
        },
    },
    tooltip: { y: { formatter: (v: any) => `${v} views (${totalTraffic > 0 ? ((v / totalTraffic) * 100).toFixed(1) : 0}%)` } },
});

// ─── Interactions ─────────────────────────────────────────────────────────────

const interactionLabels: Record<string, string> = {
    whatsapp_click: "WhatsApp", call_click: "Chamada", show_phone: "Mostrar Telefone",
    copy_phone: "Copiar Telefone", favorite: "Favorito", share: "Partilha",
    form_open: "Abrir Formulário", form_start: "Iniciar Formulário", location_view: "Ver Localização",
};
const interactionIcons: Record<string, string> = {
    whatsapp_click: "ri-whatsapp-line", call_click: "ri-phone-line", show_phone: "ri-smartphone-line",
    copy_phone: "ri-file-copy-line", favorite: "ri-heart-line", share: "ri-share-line",
    form_open: "ri-file-list-line", form_start: "ri-edit-line", location_view: "ri-map-pin-line",
};
const interactionColors: Record<string, string> = {
    whatsapp_click: "success", call_click: "info", show_phone: "warning",
    copy_phone: "secondary", favorite: "danger", share: "primary",
    form_open: "dark", form_start: "muted", location_view: "primary",
};

export const buildInteractions = (raw: any[]) =>
    [...(raw || [])]
        .map((item: any) => ({
            key: item.interaction_type,
            label: interactionLabels[item.interaction_type] || item.interaction_type,
            total: Number(item.total || 0),
            icon: interactionIcons[item.interaction_type] || "ri-cursor-line",
            color: interactionColors[item.interaction_type] || "muted",
        }))
        .sort((a, b) => b.total - a.total);

// ─── Insight automático ───────────────────────────────────────────────────────

export const buildInsight = (met: any) => {
    if (!met || met.views === 0)
        return { title: "Sem dados suficientes", text: "Este carro ainda não gerou tráfego suficiente.", rec: "Reforce a divulgação do anúncio.", icon: "ri-search-eye-line", color: "muted", bg: "bg-light" };
    if (met.views > 0 && met.interactions === 0)
        return { title: "Tráfego sem intenção", text: "Recebe visualizações, mas sem interações de contacto ainda.", rec: "Reveja preço, fotos e descrição. Se continuar, reforce a distribuição.", icon: "ri-eye-line", color: "warning", bg: "bg-warning-subtle" };
    if (met.interactions > 0 && met.leads === 0)
        return { title: "Interesse do mercado", text: "Está a gerar ações de contacto, mas ainda sem leads.", rec: "Acompanhe os contactos via WhatsApp e chamada. Este carro demonstra intenção comercial.", icon: "ri-cursor-line", color: "info", bg: "bg-info-subtle" };
    if (met.leads > 0)
        return { title: "Conversão registada", text: "Este carro está a converter visitas em leads.", rec: "Mantenha a promoção ativa e acompanhe rapidamente os contactos.", icon: "ri-checkbox-circle-line", color: "success", bg: "bg-success-subtle" };
    return { title: "Análise em curso", text: "Os dados estão a ser analisados.", rec: "Continue a acompanhar a evolução do anúncio.", icon: "ri-line-chart-line", color: "muted", bg: "bg-light" };
};

// ─── Timeline ─────────────────────────────────────────────────────────────────

export const timelineDesc = (item: any): string => {
    if (item.type === "car_created") return "O anúncio foi publicado na plataforma.";
    if (item.type === "view_group") {
        const parts = [];
        if (item.count) parts.push(item.count === 1 ? "1 visualização" : `${item.count} visualizações`);
        if (item.unique_visitors) parts.push(`${item.unique_visitors} visitante${item.unique_visitors > 1 ? "s" : ""} único${item.unique_visitors > 1 ? "s" : ""}`);
        return parts.join(" • ") || "Visualizações registadas.";
    }
    if (item.type === "interaction") return "Ação de intenção registada neste anúncio.";
    if (item.type === "lead") return "Lead gerada associada a este carro.";
    return "Atividade registada.";
};

// ─── IPS ──────────────────────────────────────────────────────────────────────

export const ipsScoreColor = (score: number) =>
    score >= 70 ? "#0ab39c" : score >= 40 ? "#f7b84b" : "#f06548";

export const ipsClassBadge = (cls: string) =>
    cls === "hot" ? "bg-success-subtle text-success" : cls === "warm" ? "bg-warning-subtle text-warning" : "bg-danger-subtle text-danger";

export const ipsFactorLabels: Record<string, { label: string; max: number; icon: string; color: string }> = {
    price_vs_market: { label: "Preço vs Mercado", max: 25, icon: "ri-price-tag-3-line", color: "primary" },
    engagement_rate: { label: "Engajamento", max: 20, icon: "ri-cursor-line", color: "info" },
    days_in_stock: { label: "Dias em Stock", max: 20, icon: "ri-time-line", color: "warning" },
    segment_demand: { label: "Procura do Segmento", max: 15, icon: "ri-bar-chart-line", color: "success" },
    listing_quality: { label: "Qualidade do Anúncio", max: 10, icon: "ri-image-line", color: "purple" },
    model_history: { label: "Histórico do Modelo", max: 10, icon: "ri-history-line", color: "secondary" },
};

export const buildIpsRadialOptions = (ips: any): any => ({
    chart: { type: "radialBar", sparkline: { enabled: true } },
    plotOptions: {
        radialBar: {
            startAngle: -90, endAngle: 90,
            track: { background: "#e9ebec", strokeWidth: "97%", margin: 5 },
            dataLabels: {
                name: { show: false },
                value: { offsetY: -2, fontSize: "28px", fontWeight: 700, formatter: (v: any) => `${v}`, color: "#212529" },
            },
            hollow: { size: "60%" },
        },
    },
    fill: { colors: [ips ? ipsScoreColor(ips.score) : "#e9ebec"] },
    stroke: { lineCap: "round" },
    labels: ["IPS"],
});

export const buildIpsHistoryOptions = (ips: any): any => ({
    chart: { type: "line", toolbar: { show: false }, sparkline: { enabled: true } },
    stroke: { curve: "smooth", width: 2 },
    colors: [ips ? ipsScoreColor(ips.score) : "#405189"],
    tooltip: { y: { formatter: (v: any) => `Score: ${v}` } },
    markers: { size: 3 },
});

// ─── Forecast chart ───────────────────────────────────────────────────────────

export const forecastOptions: any = {
    chart: { type: "area", toolbar: { show: false }, sparkline: { enabled: false } },
    dataLabels: { enabled: false },
    stroke: { curve: "smooth", width: 2 },
    fill: { type: "gradient", gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
    xaxis: { categories: ["Hoje", "7 dias", "14 dias", "30 dias"], labels: { style: { fontSize: "11px" } } },
    yaxis: { min: 0, max: 100, labels: { formatter: (v: any) => `${v}%`, style: { fontSize: "11px" } } },
    colors: ["#405189"],
    tooltip: { y: { formatter: (v: any) => `${v}% probabilidade` } },
    grid: { borderColor: "#f3f3f9", strokeDashArray: 4 },
    markers: { size: 4 },
};