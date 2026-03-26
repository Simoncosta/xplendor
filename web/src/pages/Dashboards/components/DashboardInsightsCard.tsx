import { Col } from "reactstrap";

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

const compactText = (text: string) => {
    const normalized = text
        .replaceAll("STATION_WAGON", "Carrinha")
        .replaceAll("SUV_TT", "SUV / TT")
        .replaceAll("CITY_CAR", "Citadino")
        .replaceAll("GASOLINE", "Gasolina")
        .replaceAll("ELECTRIC", "Eletrico")
        .replaceAll("DIESEL", "Diesel");

    return normalized.length > 110 ? `${normalized.slice(0, 107)}...` : normalized;
};

export default function DashboardInsightsCard({ insights }: DashboardInsightsCardProps) {
    const safeInsights = Array.isArray(insights)
        ? insights.filter((insight) => insight && (insight.title || insight.text)).slice(0, 3)
        : [];

    return (
        <Col xl={6}>
            <section style={{ border: "1px solid #e9ebec", borderRadius: 16, padding: "16px 18px", background: "#fff" }}>
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Insights automaticos
                        </p>
                        <h5 className="mb-1 fw-semibold">O que merece leitura rapida</h5>
                    </div>
                </div>

                {safeInsights.length > 0 ? (
                    <div className="vstack gap-2">
                        {safeInsights.map((insight, index) => (
                            <div key={`${insight.type}-${index}`} className="d-flex align-items-center gap-3 py-2 border-top">
                                <i className={`${getInsightIcon(insight.type)} text-primary fs-4 flex-shrink-0`} />
                                <div style={{ minWidth: 0 }}>
                                    <div className="fw-semibold fs-13">{insight.title}</div>
                                    <div className="text-muted fs-12 text-truncate">{compactText(insight.text)}</div>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="text-muted fs-13">Ainda nao ha insights suficientes para destacar padroes fiaveis.</div>
                )}
            </section>
        </Col>
    );
}
