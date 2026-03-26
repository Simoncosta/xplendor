import { Link } from "react-router-dom";
import { Col } from "reactstrap";

type SilentBuyerSummary = {
    total_detected?: number;
    affected_cars_count?: number;
    average_intent_score?: number;
    recent_window_days?: number;
    executive_alert?: string;
    recommended_focus?: string;
    top_affected_cars?: Array<{
        car_id: number;
        car_name: string;
        silent_buyers_count: number;
        avg_intent_score: number;
        max_intent_score: number;
        dominant_action?: string;
    }>;
};

type Props = {
    summary?: SilentBuyerSummary | null;
};

export default function SilentBuyerExecutiveCard({ summary }: Props) {
    const totalDetected = Number(summary?.total_detected || 0);
    const averageIntentScore = Number(summary?.average_intent_score || 0);
    const recentWindowDays = Number(summary?.recent_window_days || 30);
    const targetCar = summary?.top_affected_cars?.[0];

    return (
        <Col xl={6}>
            <section style={{ border: "1px solid #e9ebec", borderRadius: 16, padding: "16px 18px", background: "#fff" }}>
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Silent Buyer
                        </p>
                        <h5 className="mb-1 fw-semibold">Alerta inteligente</h5>
                        <p className="text-muted fs-13 mb-0">Visitantes com intencao real sem lead formal nos ultimos {recentWindowDays} dias.</p>
                    </div>
                    <Link
                        to={targetCar ? `/cars/${targetCar.car_id}/marketing` : "/cars"}
                        className="btn btn-soft-success btn-sm"
                    >
                        <i className="ri-whatsapp-line me-1" />
                        Ativar WhatsApp
                    </Link>
                </div>

                <div className="d-flex align-items-center justify-content-between gap-3 flex-wrap border-top pt-3">
                    <div>
                        <div className="text-muted fs-12 text-uppercase fw-semibold mb-1">Compradores silenciosos</div>
                        <div className="fw-semibold fs-4">{totalDetected}</div>
                    </div>
                    <div>
                        <div className="text-muted fs-12 text-uppercase fw-semibold mb-1">Score medio</div>
                        <div className="fw-semibold fs-4">{averageIntentScore.toFixed(0)}/100</div>
                    </div>
                    <div className="text-muted fs-13">
                        {summary?.recommended_focus || summary?.executive_alert || "Monitorizar os carros com maior recorrencia e interacao."}
                    </div>
                </div>
            </section>
        </Col>
    );
}
