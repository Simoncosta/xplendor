import { Col } from "reactstrap";
import { IMarketingRoi } from "./marketingRoi.types";
import { formatCurrency, formatCurrencyWithFallback } from "./marketingRoi.utils";

type MarketingRoiSummaryCardsProps = {
    marketingRoi: IMarketingRoi;
};

const executiveCards = (marketingRoi: IMarketingRoi) => [
    {
        label: "Investimento",
        value: formatCurrency(marketingRoi.summary.total_spend),
    },
    {
        label: "Leads",
        value: marketingRoi.summary.total_leads.toLocaleString("pt-PT"),
    },
    {
        label: "CPL medio",
        value: formatCurrencyWithFallback(marketingRoi.summary.avg_cost_per_lead),
    },
    {
        label: "Melhor canal",
        value: marketingRoi.summary.best_channel || "Sem dados",
    },
];

export default function MarketingRoiSummaryCards({ marketingRoi }: MarketingRoiSummaryCardsProps) {
    const cards = executiveCards(marketingRoi);

    return (
        <Col xs={12}>
            <section style={{ border: "1px solid #e9ebec", borderRadius: 16, padding: "16px 18px", background: "#fff" }}>
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Marketing intelligence
                        </p>
                        <h5 className="mb-1 fw-semibold">ROI e decisao de investimento</h5>
                    </div>
                    <span className="badge bg-light text-dark px-3 py-2">
                        {marketingRoi.summary.best_campaign || "Sem campanha vencedora"}
                    </span>
                </div>
                <div className="row g-2">
                    {cards.map((card) => (
                        <div className="col-xl-3 col-md-6" key={card.label}>
                            <div className="rounded-3 bg-light-subtle h-100" style={{ padding: "14px 16px" }}>
                                <div className="text-muted fs-12 text-uppercase fw-semibold mb-1">{card.label}</div>
                                <div className="fw-semibold">{card.value}</div>
                            </div>
                        </div>
                    ))}
                </div>
            </section>
        </Col>
    );
}
