import { Card, CardBody, CardHeader, Col } from "reactstrap";

type MarketingRoiInsightsCardProps = {
    insights: string[];
};

export default function MarketingRoiInsightsCard({
    insights,
}: MarketingRoiInsightsCardProps) {
    return (
        <Col xl={4}>
            <Card className="card-height-100 border-0 shadow-sm bg-opacity-50">
                <CardHeader className="bg-transparent border-0">
                    <h4 className="card-title mb-0">Insights automáticos</h4>
                </CardHeader>

                <CardBody>
                    {insights.length > 0 ? (
                        <div className="vstack gap-3">
                            {insights.map((insight, index) => (
                                <div key={`${insight}-${index}`} className="border rounded-3 p-3 bg-white">
                                    <div className="d-flex gap-3">
                                        <div className="flex-shrink-0 text-primary fs-4">
                                            <i className="ri-focus-3-line" />
                                        </div>
                                        <p className="mb-0 text-muted">{insight}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-muted mb-0">
                            Assim que houver histórico de campanhas e leads suficientes, os insights aparecem aqui automaticamente.
                        </p>
                    )}
                </CardBody>
            </Card>
        </Col>
    );
}
