import React from "react";
import ReactApexChart from "react-apexcharts";
import { Card, CardBody, CardHeader, Col, Row } from "reactstrap";
import getChartColorsArray from "Components/Common/ChartsDynamicColor";

interface IMarketingPerformance {
    views_last_7_days: number;
    leads_last_7_days: number;
    interactions_last_7_days: number;
    interest_rate: number;
    meta_clicks_last_7_days?: number;
    meta_reach_last_7_days?: number;
    meta_spend_last_7_days?: number;
    traffic_distribution: Array<{
        channel: string;
        label: string;
        count: number;
        percentage: number;
    }>;
}

type MarketingTrafficDonutChartProps = {
    marketingPerformance: IMarketingPerformance;
    dataColors?: string;
};

export default function MarketingTrafficDonutChart({
    marketingPerformance,
    dataColors = '["--vz-primary", "--vz-success", "--vz-warning", "--vz-info", "--vz-danger", "--vz-secondary", "--vz-dark"]',
}: MarketingTrafficDonutChartProps) {
    const defaultDistribution = [
        { channel: "paid", label: "Trafego pago", count: 0, percentage: 0 },
        { channel: "organic_search", label: "Pesquisa organica", count: 0, percentage: 0 },
        { channel: "direct", label: "Direto", count: 0, percentage: 0 },
    ];
    const distribution = marketingPerformance?.traffic_distribution?.length
        ? marketingPerformance.traffic_distribution
        : defaultDistribution;
    const chartColors = getChartColorsArray(dataColors).slice(0, distribution.length);

    const series = distribution.map((item) => item.percentage || 0);
    const labels = distribution.map((item) => item.label);
    const hasMarketingSignals = series.some((value) => value > 0);

    const options: ApexCharts.ApexOptions = {
        chart: {
            height: 280,
            type: "donut",
        },
        labels,
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
                            label: "Canais",
                            formatter: () => hasMarketingSignals ? "100%" : "0%",
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
                        📈 Performance de Marketing
                    </h4>
                </CardHeader>

                <CardBody>
                    <Row className="align-items-center">
                        <Col lg={7}>
                            {hasMarketingSignals ? (
                                <ReactApexChart
                                    dir="ltr"
                                    className="apex-charts"
                                    series={series}
                                    options={options}
                                    type="donut"
                                    height={280}
                                />
                            ) : (
                                <div className="h-100 d-flex align-items-center justify-content-center">
                                    <div className="text-center px-3">
                                        <div className="avatar-sm mx-auto mb-3">
                                            <div className="avatar-title bg-light text-muted rounded-circle fs-3">
                                                <i className="ri-pie-chart-line" />
                                            </div>
                                        </div>
                                        <h6 className="mb-1">Sem sinais de marketing</h6>
                                        <p className="text-muted mb-0">
                                            O gráfico aparece automaticamente quando existirem sinais em views, interações, leads ou Meta Ads no período.
                                        </p>
                                    </div>
                                </div>
                            )}
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
