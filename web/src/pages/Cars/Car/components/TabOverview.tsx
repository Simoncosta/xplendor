import { Col, Row } from "reactstrap";
import SmartAdsRecommendationCard from "./SmartAdsRecommendationCard";

interface Props {
    recommendation: any;
    recommendedCreative?: any;
    recommendedPlatform?: "meta" | "google" | null;
    silentBuyers: any;
    metrics: any;
    insight: any;
    onOpenIntelligence: () => void;
    marketingUrl: string;
}

export default function TabOverview({
    recommendation,
    recommendedCreative,
    recommendedPlatform,
    silentBuyers,
    metrics,
    insight,
    onOpenIntelligence,
    marketingUrl,
}: Props) {
    return (
        <div className="d-grid gap-3">
            <SmartAdsRecommendationCard
                recommendation={recommendation}
                recommendedCreative={recommendedCreative}
                recommendedPlatform={recommendedPlatform}
                marketingUrl={marketingUrl}
                metrics={metrics}
            />

            <section style={sectionStyle}>
                <div className="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                    <div className="d-flex align-items-center gap-3 flex-wrap">
                        <span className="badge bg-warning-subtle text-warning fs-12 px-3 py-2">
                            Silent Buyer
                        </span>
                        <span className="fs-13 text-muted">
                            {silentBuyers?.visitors_count ?? 0} visitantes · {Number(silentBuyers?.average_intent_score ?? 0).toFixed(0)}/100 score médio · {formatDuration(silentBuyers?.total_view_duration_seconds ?? 0)}
                        </span>
                    </div>
                    <button type="button" className="btn btn-sm btn-soft-primary" onClick={onOpenIntelligence}>
                        Ver detalhe
                    </button>
                </div>
            </section>

            <section style={sectionStyle}>
                <Row className="g-3 align-items-start">
                    <Col lg={8}>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Insight automático
                        </p>
                        <h6 className="mb-2 fw-semibold">{insight?.title ?? "Sem insight disponível"}</h6>
                        <p className="text-muted fs-14 mb-0" style={{ lineHeight: 1.6 }}>
                            {insight?.text ?? "Assim que houver mais sinais comportamentais, surge aqui uma leitura automática."}
                        </p>
                    </Col>
                    <Col lg={4}>
                        <div className="d-flex align-items-center justify-content-between gap-2">
                            <span className="text-muted fs-12">Próximo passo</span>
                            <i className={`${insight?.icon ?? "ri-lightbulb-flash-line"} text-${insight?.color ?? "warning"} fs-20`} />
                        </div>
                        <p className="mb-0 fs-13 fw-medium mt-2">{insight?.rec ?? "Continuar a monitorizar a performance do anúncio."}</p>
                    </Col>
                </Row>
            </section>

            <section style={sectionStyle}>
                <div className="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                    <span className="text-muted fs-13">Views {metrics?.views ?? 0} · Leads {metrics?.leads ?? 0} · Conversão {Number(metrics?.interest_rate ?? 0).toFixed(2)}%</span>
                    <span className="text-muted fs-12">Foco: decisão rápida e ação imediata</span>
                </div>
            </section>
        </div>
    );
}

const sectionStyle = {
    padding: "16px 18px",
    border: "1px solid #e9ebec",
    borderRadius: "16px",
    background: "#fff",
};

function formatDuration(seconds: number) {
    const total = Number(seconds || 0);
    const minutes = Math.floor(total / 60);
    const remainingSeconds = total % 60;

    if (minutes <= 0) {
        return `${remainingSeconds}s`;
    }

    return `${minutes}m ${remainingSeconds}s`;
}
