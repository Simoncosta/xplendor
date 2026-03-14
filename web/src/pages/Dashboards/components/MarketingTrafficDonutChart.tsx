import React from "react";
import ReactApexChart from "react-apexcharts";
import { Card, CardBody, CardHeader, Col, Row } from "reactstrap";
import getChartColorsArray from "Components/Common/ChartsDynamicColor";

interface IMarketingPerformance {
    views_last_7_days: number;
    leads_last_7_days: number;
    interactions_last_7_days: number;
    interest_rate: number;
    traffic_distribution: {
        meta_ads: number;
        google: number;
        organic: number;
    };
}

type MarketingTrafficDonutChartProps = {
    marketingPerformance: IMarketingPerformance;
    dataColors?: string;
};

export default function MarketingTrafficDonutChart({
    marketingPerformance,
    dataColors = '["--vz-primary", "--vz-success", "--vz-warning"]',
}: MarketingTrafficDonutChartProps) {
    const chartColors = getChartColorsArray(dataColors);

    const series = [
        marketingPerformance?.traffic_distribution?.meta_ads || 0,
        marketingPerformance?.traffic_distribution?.google || 0,
        marketingPerformance?.traffic_distribution?.organic || 0,
    ];

    const options: ApexCharts.ApexOptions = {
        chart: {
            height: 280,
            type: "donut",
        },
        labels: ["Meta Ads", "Google", "Orgânico"],
        legend: {
            position: "bottom",
            horizontalAlign: "center",
        },
        dataLabels: {
            enabled: true,
            formatter: (val: number) => `${val.toFixed(1)}%`,
            dropShadow: {
                enabled: false,
            },
        },
        colors: chartColors,
        stroke: {
            width: 0,
        },
        tooltip: {
            y: {
                formatter: (val: number) => `${val.toFixed(1)}%`,
            },
        },
        plotOptions: {
            pie: {
                donut: {
                    size: "72%",
                    labels: {
                        show: true,
                        total: {
                            show: true,
                            label: "Tráfego",
                            formatter: () => "100%",
                        },
                    },
                },
            },
        },
    };

    return (
        <Col xl={6}>
            <Card className="card-height-100">
                <CardHeader className="align-items-center d-flex">
                    <h4 className="card-title mb-0 flex-grow-1">
                        Performance de Marketing
                    </h4>
                </CardHeader>

                <CardBody>
                    <Row className="align-items-center">
                        <Col lg={7}>
                            <ReactApexChart
                                dir="ltr"
                                className="apex-charts"
                                series={series}
                                options={options}
                                type="donut"
                                height={280}
                            />
                        </Col>

                        <Col lg={5}>
                            <div className="vstack gap-3">
                                <div className="border rounded p-3">
                                    <p className="text-muted mb-1">Views 7 dias</p>
                                    <h5 className="mb-0">
                                        {marketingPerformance?.views_last_7_days?.toLocaleString("pt-PT")}
                                    </h5>
                                </div>

                                <div className="border rounded p-3">
                                    <p className="text-muted mb-1">Leads 7 dias</p>
                                    <h5 className="mb-0">
                                        {marketingPerformance?.leads_last_7_days?.toLocaleString("pt-PT")}
                                    </h5>
                                </div>

                                <div className="border rounded p-3">
                                    <p className="text-muted mb-1">Interações 7 dias</p>
                                    <h5 className="mb-0">
                                        {marketingPerformance?.interactions_last_7_days?.toLocaleString("pt-PT")}
                                    </h5>
                                </div>

                                <div className="border rounded p-3">
                                    <p className="text-muted mb-1">Taxa de interesse</p>
                                    <h5 className="mb-0">
                                        {marketingPerformance?.interest_rate?.toFixed(2)}%
                                    </h5>
                                </div>
                            </div>
                        </Col>
                    </Row>
                </CardBody>
            </Card>
        </Col>
    );
}