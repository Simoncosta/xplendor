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

export const buildKpiItems = (m?: any) => {
    const metrics = {
        views: Number(m?.views || 0),
        views_24h: Number(m?.views_24h || 0),
        views_7d: Number(m?.views_7d || 0),
        interactions: Number(m?.interactions || 0),
        leads: Number(m?.leads || 0),
        interest_rate: Number(m?.interest_rate || 0),
    };

    return [
        { id: 1, label: "Views", counter: metrics.views, icon: "ri-eye-line", iconClass: "text-primary", suffix: "", decimals: 0, valueClass: "text-body", badge: "" },
        { id: 2, label: "Views 24h", counter: metrics.views_24h, icon: "ri-time-line", iconClass: metrics.views_24h > 0 ? "text-info" : "text-muted", suffix: "", decimals: 0, valueClass: "text-body", badge: "" },
        { id: 3, label: "Views 7 dias", counter: metrics.views_7d, icon: "ri-calendar-line", iconClass: metrics.views_7d > 0 ? "text-secondary" : "text-muted", suffix: "", decimals: 0, valueClass: "text-body", badge: "" },
        { id: 4, label: "Interações", counter: metrics.interactions, icon: "ri-cursor-line", iconClass: metrics.interactions > 0 ? "text-success" : "text-muted", suffix: "", decimals: 0, valueClass: metrics.interactions > 0 ? "text-success" : "text-body", badge: "" },
        { id: 5, label: "Leads", counter: metrics.leads, icon: "ri-user-follow-line", iconClass: metrics.leads > 0 ? "text-success" : "text-muted", suffix: "", decimals: 0, valueClass: metrics.leads > 0 ? "text-success" : "text-body", badge: "" },
        { id: 6, label: "Interesse do mercado", counter: metrics.interest_rate, icon: "ri-line-chart-line", iconClass: interestRateColor(metrics.interest_rate), suffix: "%", decimals: 1, valueClass: interestRateColor(metrics.interest_rate), badge: interestRateBadge(metrics.interest_rate) },
    ];
};

// ─── Traffic sources ──────────────────────────────────────────────────────────

export const buildTrafficSources = (rawSources: any[]) =>
    [...(rawSources || [])]
        .map((item: any) => ({
            key: String(item?.channel || "unknown"),
            label: channelLabels[String(item?.channel || "unknown")] || String(item?.channel || "Desconhecido"),
            total: Number(item.total || 0),
            color: channelColors[String(item?.channel || "unknown")] || "#adb5bd",
        }))
        .sort((a, b) => b.total - a.total);

export const buildDonutOptions = (trafficSources: any[], totalTraffic: number): any => ({
    chart: { type: "donut", toolbar: { show: false } },
    labels: trafficSources.map((i) => String(i?.label || "Desconhecido")),
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
                    value: { show: true, fontSize: "22px", fontWeight: 700, offsetY: -10, formatter: (v: any) => parseInt(String(v ?? 0), 10) || 0 },
                    total: { show: true, label: "Views", fontSize: "14px", fontWeight: 500, formatter: () => Number(totalTraffic || 0) },
                },
            },
        },
    },
    tooltip: { y: { formatter: (v: any) => `${Number(v || 0)} views (${totalTraffic > 0 ? ((Number(v || 0) / totalTraffic) * 100).toFixed(1) : 0}%)` } },
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
            key: String(item?.interaction_type || "unknown"),
            label: interactionLabels[String(item?.interaction_type || "unknown")] || String(item?.interaction_type || "Interação"),
            total: Number(item.total || 0),
            icon: interactionIcons[String(item?.interaction_type || "unknown")] || "ri-cursor-line",
            color: interactionColors[String(item?.interaction_type || "unknown")] || "muted",
        }))
        .sort((a, b) => b.total - a.total);

// ─── Insight automático ───────────────────────────────────────────────────────

export const buildInsight = (met: any) => {
    if (!met || met.views === 0)
        return { title: "Este carro ainda não foi visto", text: "Este carro ainda não foi visto. Partilha o link ou cria um anúncio.", rec: "Distribui a viatura para começar a gerar sinais reais.", icon: "ri-search-eye-line", color: "muted", bg: "bg-light" };
    if (met.views > 0 && met.interactions === 0)
        return { title: "Há visualizações mas pouco envolvimento real", text: "Este carro está a receber atenção, mas ainda sem sinais claros de contacto.", rec: "Revê preço, fotos e proposta antes de reforçar investimento.", icon: "ri-eye-line", color: "warning", bg: "bg-warning-subtle" };
    if (met.interactions > 0 && met.leads === 0)
        return { title: "Há interesse mas ninguém contactou ainda", text: "Há sinais de intenção, mas ainda sem lead formal.", rec: "Verifica o preço, as fotos e o tempo de resposta comercial.", icon: "ri-cursor-line", color: "info", bg: "bg-info-subtle" };
    if (met.leads > 0)
        return { title: "O mercado está a responder", text: "Este carro já está a transformar atenção em contactos.", rec: "Mantém o ritmo e acelera o follow-up comercial.", icon: "ri-checkbox-circle-line", color: "success", bg: "bg-success-subtle" };
    return { title: "A recolher dados", text: "Ainda não existe padrão suficiente para uma leitura firme.", rec: "Continua a acompanhar a evolução desta viatura.", icon: "ri-line-chart-line", color: "muted", bg: "bg-light" };
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

export const ipsExecutiveLabel = (cls?: string) =>
    cls === "hot" ? "Probabilidade forte" : cls === "warm" ? "Acompanhar esta semana" : "Precisa de atenção agora";

export const ipsFactorLabels: Record<string, { label: string; max: number; icon: string; color: string }> = {
    price_vs_market: { label: "Preço vs Mercado", max: 25, icon: "ri-price-tag-3-line", color: "primary" },
    engagement_rate: { label: "Engajamento", max: 20, icon: "ri-cursor-line", color: "info" },
    days_in_stock: { label: "Dias em Stock", max: 20, icon: "ri-time-line", color: "warning" },
    segment_demand: { label: "Procura do Segmento", max: 15, icon: "ri-bar-chart-line", color: "success" },
    listing_quality: { label: "Qualidade do Anúncio", max: 10, icon: "ri-image-line", color: "purple" },
    model_history: { label: "Histórico do Modelo", max: 10, icon: "ri-history-line", color: "secondary" },
};

export const marketPositionMeta: Record<string, { label: string; className: string; impact: string; description: string }> = {
    below_market: {
        label: "Abaixo do mercado",
        className: "bg-success-subtle text-success",
        impact: "positivo",
        description: "Preço competitivo face ao mercado.",
    },
    aligned_market: {
        label: "Alinhado com o mercado",
        className: "bg-info-subtle text-info",
        impact: "neutro",
        description: "Preço alinhado com o mercado.",
    },
    above_market: {
        label: "Acima do mercado",
        className: "bg-warning-subtle text-warning",
        impact: "negativo",
        description: "Preço acima da mediana e pode travar conversão.",
    },
    insufficient_data: {
        label: "A recolher dados",
        className: "bg-secondary-subtle text-secondary",
        impact: "neutro",
        description: "Ainda não existe massa crítica para leitura fiável.",
    },
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
