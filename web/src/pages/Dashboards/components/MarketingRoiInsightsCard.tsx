import { Col } from "reactstrap";

type MarketingRoiInsightsCardProps = {
    insights: string[];
};

export default function MarketingRoiInsightsCard({ insights }: MarketingRoiInsightsCardProps) {
    const safeInsights = (insights || []).slice(0, 3);

    return (
        <Col xl={4}>
            <section style={{ border: "1px solid #e9ebec", borderRadius: 16, padding: "16px 18px", background: "#fff" }}>
                <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                    ROI insights
                </p>
                <h6 className="mb-3 fw-semibold">Leitura rapida</h6>

                {safeInsights.length > 0 ? (
                    <div className="vstack gap-2">
                        {safeInsights.map((insight, index) => (
                            <div key={`${insight}-${index}`} className="d-flex gap-2 py-2 border-top">
                                <i className="ri-focus-3-line text-primary fs-5 flex-shrink-0" />
                                <p className="mb-0 text-muted fs-13">{insight}</p>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-muted mb-0 fs-13">Assim que houver historico suficiente, os insights aparecem aqui automaticamente.</p>
                )}
            </section>
        </Col>
    );
}
