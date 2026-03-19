import { Card, CardBody, CardHeader, Col } from "reactstrap";

interface IDashboardInsight {
    type: string;
    title: string;
    text: string;
    meta?: Record<string, any>;
}

type DashboardInsightsCardProps = {
    insights?: IDashboardInsight[];
};

const getInsightIcon = (type: string) => {
    switch (type) {
        case "segment_performance":
            return "ri-roadster-line";
        case "fuel_performance":
            return "ri-flashlight-line";
        case "brand_performance":
            return "ri-award-line";
        default:
            return "ri-lightbulb-flash-line";
    }
};

const normalizeLabel = (value?: string) => {
    if (!value) return "-";

    const map: Record<string, string> = {
        station_wagon: "Carrinha",
        suv_tt: "SUV / TT",
        city_car: "Citadino",
        hatchback: "Hatchback",
        sedan: "Sedan",
        coupe: "Coupé",
        convertible: "Cabrio",

        gasoline: "Gasolina",
        diesel: "Diesel",
        electric: "Elétrico",
        hybrid: "Híbrido",
        plug_in_hybrid: "Híbrido Plug-in",
    };

    const normalized = value.toLowerCase().trim();

    if (map[normalized]) {
        return map[normalized];
    }

    return normalized
        .replaceAll("_", " ")
        .replace(/\b\w/g, (char) => char.toUpperCase());
};

const formatPercent = (value?: number) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return "-";
    }

    return `${Number(value).toFixed(2)}%`;
};

const formatCount = (value?: number) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return "0";
    }

    return Number(value).toLocaleString("pt-PT");
};

const normalizeInsightText = (text: string) => {
    return text
        .replaceAll("STATION_WAGON", "Carrinha")
        .replaceAll("SUV_TT", "SUV / TT")
        .replaceAll("CITY_CAR", "Citadino")
        .replaceAll("GASOLINE", "Gasolina")
        .replaceAll("ELECTRIC", "Elétrico")
        .replaceAll("DIESEL", "Diesel")
        .replaceAll(".", ",")
        .replace(/(\d+),(\d+)%/, "$1,$2%");
};

const renderInsightMeta = (insight: IDashboardInsight) => {
    if (!insight.meta) return null;

    switch (insight.type) {
        case "segment_performance":
            return (
                <div className="d-flex flex-wrap gap-2">
                    <span className="badge bg-light text-dark">
                        Segmento: {normalizeLabel(insight.meta.label)}
                    </span>
                    <span className="badge bg-light text-dark">
                        Views: {insight.meta.views_count ?? 0}
                    </span>
                    <span className="badge bg-light text-dark">
                        Leads: {insight.meta.leads_count ?? 0}
                    </span>
                    <span className="badge bg-light text-dark">
                        Conversão: {formatPercent(insight.meta.conversion_rate)}
                    </span>
                </div>
            );

        case "fuel_performance":
            return (
                <div className="d-flex flex-wrap gap-2">
                    <span className="badge bg-light text-dark">
                        Melhor: {normalizeLabel(insight.meta.best_label)} ({formatPercent(insight.meta.best_conversion_rate)})
                    </span>
                    <span className="badge bg-light text-dark">
                        Views: {formatCount(insight.meta.best_views_count)}
                    </span>
                    <span className="badge bg-light text-dark">
                        Leads: {formatCount(insight.meta.best_leads_count)}
                    </span>
                    {insight.meta.second_label && (
                        <span className="badge bg-light text-dark">
                            Seguinte: {normalizeLabel(insight.meta.second_label)} ({formatPercent(insight.meta.second_conversion_rate)})
                        </span>
                    )}
                </div>
            );

        case "brand_performance":
            return (
                <div className="d-flex flex-wrap gap-2">
                    <span className="badge bg-light text-dark">
                        Melhor: {insight.meta.best_label ?? "-"} ({formatPercent(insight.meta.best_conversion_rate)})
                    </span>
                    <span className="badge bg-light text-dark">
                        Views: {formatCount(insight.meta.best_views_count)}
                    </span>
                    <span className="badge bg-light text-dark">
                        Leads: {formatCount(insight.meta.best_leads_count)}
                    </span>
                    {insight.meta.second_label && (
                        <span className="badge bg-light text-dark">
                            Seguinte: {insight.meta.second_label ?? "-"} ({formatPercent(insight.meta.second_conversion_rate)})
                        </span>
                    )}
                </div>
            );

        default:
            return null;
    }
};

export default function DashboardInsightsCard({
    insights,
}: DashboardInsightsCardProps) {
    const safeInsights = Array.isArray(insights)
        ? insights.filter((insight) => insight && (insight.title || insight.text))
        : [];

    return (
        <Col xl={6}>
            <Card className="card-height-100">
                <CardHeader className="align-items-center d-flex">
                    <h4 className="card-title mb-0 flex-grow-1">🧠 Insights Xplendor</h4>
                </CardHeader>

                {
                    safeInsights.length > 0 ? (

                        <CardBody>
                            <div className="vstack gap-3">
                                {safeInsights.map((insight, index) => (
                                    <div
                                        key={`${insight.type}-${index}`}
                                        className="border rounded p-3"
                                    >
                                        <div className="d-flex align-items-start gap-3">
                                            <div className="flex-shrink-0">
                                                <div className="avatar-xs">
                                                    <div className="avatar-title bg-primary-subtle text-primary rounded-circle fs-5">
                                                        <i className={getInsightIcon(insight.type)} />
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="flex-grow-1">
                                                <h5 className="fs-14 mb-1">{insight.title}</h5>
                                                <p className="text-muted mb-2">
                                                    {normalizeInsightText(insight.text)}
                                                </p>
                                                {renderInsightMeta(insight)}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardBody>
                    ) : (
                        <CardBody>
                            <div className="border rounded p-3 bg-light-subtle">
                                <p className="fs-16 lh-base mb-2">
                                    Ainda não há insights suficientes para destacar padrões fiáveis no stock.
                                </p>
                                <p className="text-muted mb-0">
                                    Assim que houver mais histórico de visitas, interações e leads por segmento, combustível e marca, os insights aparecem aqui automaticamente.
                                </p>
                            </div>
                            <div className="d-flex flex-wrap gap-2 mt-3">
                                <span className="badge bg-light text-dark">Segmentos</span>
                                <span className="badge bg-light text-dark">Combustíveis</span>
                                <span className="badge bg-light text-dark">Marcas</span>
                            </div>
                        </CardBody>
                    )
                }
            </Card>
        </Col>
    );
}
