import React from "react";
import { Card, CardBody, CardHeader, Col, Row } from "reactstrap";
import { IMarketingRoi } from "./marketingRoi.types";
import { formatCurrency, formatCurrencyWithFallback } from "./marketingRoi.utils";

type MarketingRoiSummaryCardsProps = {
    marketingRoi: IMarketingRoi;
};

const executiveCards = (marketingRoi: IMarketingRoi) => [
    {
        label: "Investimento 7 dias",
        value: formatCurrency(marketingRoi.summary.total_spend),
        icon: "ri-funds-line",
        accentClass: "text-primary",
    },
    {
        label: "Leads 7 dias",
        value: marketingRoi.summary.total_leads.toLocaleString("pt-PT"),
        icon: "ri-user-shared-line",
        accentClass: "text-success",
    },
    {
        label: "CPL médio",
        value: formatCurrencyWithFallback(marketingRoi.summary.avg_cost_per_lead),
        icon: "ri-price-tag-3-line",
        accentClass: "text-warning",
    },
    {
        label: "Melhor canal pago",
        value: marketingRoi.summary.best_channel || "Sem dados",
        icon: "ri-rocket-line",
        accentClass: "text-info",
    },
];

export default function MarketingRoiSummaryCards({
    marketingRoi,
}: MarketingRoiSummaryCardsProps) {
    const cards = executiveCards(marketingRoi);

    return (
        <Col xl={12}>
            <Card className="card-height-100 border-0 shadow-sm">
                <CardHeader className="bg-white border-0 pb-0">
                    <div className="d-flex flex-column flex-lg-row align-items-lg-center gap-3">
                        <div className="flex-grow-1">
                            <span className="badge bg-dark-subtle text-dark text-uppercase mb-2">
                                Marketing Intelligence
                            </span>
                            <h4 className="card-title mb-1">
                                ROI e decisão de investimento
                            </h4>
                            <p className="text-muted mb-0">
                                Quanto investiste, quantas leads geraste e em que canal pago faz mais sentido escalar.
                            </p>
                        </div>

                        <div className="border rounded-3 px-3 py-2 bg-light-subtle">
                            <p className="text-muted mb-1 fs-12 text-uppercase">Melhor campanha</p>
                            <h6 className="mb-0">{marketingRoi.summary.best_campaign || "Sem campanha vencedora"}</h6>
                        </div>
                    </div>
                </CardHeader>

                <CardBody>
                    <Row>
                        {cards.map((card) => (
                            <Col xl={3} md={6} key={card.label}>
                                <div className="border rounded-3 p-3 h-100 bg-light bg-opacity-50">
                                    <div className="d-flex align-items-start justify-content-between gap-3">
                                        <div>
                                            <p className="text-muted text-uppercase fw-semibold mb-2 fs-12">
                                                {card.label}
                                            </p>
                                            <h4 className="mb-0">{card.value}</h4>
                                        </div>

                                        <div className={`fs-2 ${card.accentClass}`}>
                                            <i className={card.icon} />
                                        </div>
                                    </div>
                                </div>
                            </Col>
                        ))}
                    </Row>
                </CardBody>
            </Card>
        </Col>
    );
}
