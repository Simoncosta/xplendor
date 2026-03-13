// React
import React from 'react';
// Components
import { Card, CardBody, Col, Row } from 'reactstrap';

interface ISummaryDashboard {
    total_cars: number;
    own_stock: number;
    trade_ins: number;
    avg_price: number;
    avg_km: number;
    avg_days_in_stock: number;
}

type SummaryDashboardProps = {
    summary: ISummaryDashboard;
};

export default function SummaryDashboard({ summary }: SummaryDashboardProps) {
    const {
        total_cars,
        own_stock,
        trade_ins,
        avg_price,
        avg_km,
        avg_days_in_stock
    } = summary;

    return (
        <React.Fragment>
            <Col xl={6}>
                <div className="d-flex flex-column h-100">
                    <Row>
                        <Col xl={6} md={6}>
                            <CardShowComponent
                                label="Total de carros"
                                value={total_cars}
                                icon="ri-car-line"
                            />
                        </Col>
                        <Col xl={6} md={6}>
                            <CardShowComponent
                                label="Stock próprio"
                                value={own_stock}
                                icon="ri-archive-drawer-fill"
                            />
                        </Col>
                        <Col xl={6} md={6}>
                            <CardShowComponent
                                label="Retomas"
                                value={trade_ins}
                                icon="ri-car-washing-line"
                            />
                        </Col>
                        <Col xl={6} md={6}>
                            <CardShowComponent
                                label="Preço médio"
                                value={`€ ${avg_price}`}
                                icon="ri-money-euro-circle-line"
                            />
                        </Col>
                        <Col xl={6} md={6}>
                            <CardShowComponent
                                label="Km médio"
                                value={`${avg_km} Km`}
                                icon="ri-bookmark-line"
                            />
                        </Col>
                        <Col xl={6} md={6}>
                            <CardShowComponent
                                label="Tempo médio em stock"
                                value={`${avg_days_in_stock} dias`}
                                icon="ri-calendar-event-line"
                            />
                        </Col>
                    </Row>
                </div>
            </Col>
        </React.Fragment>
    );
}

const CardShowComponent = ({
    label,
    value,
    icon,
}: {
    label: string,
    value: number | string,
    icon: string,
}) => {
    return (
        <Card className="card-animate overflow-hidden">
            <div
                className="position-absolute start-0"
                style={{ zIndex: "0" }}
            >
                <svg
                    version="1.2"
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 200 120"
                    width="200"
                    height="120"
                >
                    <path
                        id="Shape 8"
                        style={{ opacity: ".05", fill: "#1E1E1E" }}
                        d="m189.5-25.8c0 0 20.1 46.2-26.7 71.4 0 0-60 15.4-62.3 65.3-2.2 49.8-50.6 59.3-57.8 61.5-7.2 2.3-60.8 0-60.8 0l-11.9-199.4z"
                    />
                </svg>
            </div>
            <CardBody style={{ zIndex: "1" }}>
                <div className="d-flex align-items-center">
                    <div className="flex-grow-1 overflow-hidden">
                        <p className="text-uppercase fw-semibold text-muted text-truncate mb-3">
                            {" "}{label}:{" "}
                        </p>
                        <h4 className="fs-22 fw-bold ff-secondary mb-0">
                            <span className="counter-value" data-target="36894">
                                {value}
                            </span>
                        </h4>
                    </div>
                    <div className="flex-shrink-0">
                        <span
                            className={icon + " text-dark"}
                            style={{
                                fontSize: '35px'
                            }}
                        ></span>
                    </div>
                </div>
            </CardBody>
        </Card>
    )
}