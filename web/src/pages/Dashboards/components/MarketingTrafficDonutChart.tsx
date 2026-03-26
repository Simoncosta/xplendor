import React from "react";
import ReactApexChart from "react-apexcharts";
import { Col, Row } from "reactstrap";
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
    const distribution = marketingPerformance?.traffic_distribution?.length
        ? marketingPerformance.traffic_distribution.map((item) => ({
            label: String(item?.label || "Canal"),
            percentage: Number(item?.percentage || 0),
        }))
        : [];

    const chartColors = getChartColorsArray(dataColors).slice(0, Math.max(distribution.length, 1));
    const series = distribution.map((item) => Number(item.percentage || 0));
    const labels = distribution.map((item) => item.label);
    const hasMarketingSignals = series.some((value) => value > 0);
    const views = Number(marketingPerformance?.views_last_7_days || 0);
    const leads = Number(marketingPerformance?.leads_last_7_days || 0);
    const conversion = Number(marketingPerformance?.interest_rate || 0);

    const options: ApexCharts.ApexOptions = {
        chart: { height: 220, type: "donut", toolbar: { show: false } },
        labels,
        legend: { show: false },
        dataLabels: { enabled: false },
        colors: chartColors,
        stroke: { width: 0 },
        tooltip: { y: { formatter: (val: number) => `${Number(val || 0).toFixed(1)}%` } },
        plotOptions: {
            pie: {
                donut: {
                    size: "74%",
                    labels: {
                        show: true,
                        total: {
                            show: true,
                            label: "Mix",
                            formatter: () => hasMarketingSignals ? "100%" : "0%",
                        },
                    },
                },
            },
        },
    };

    return (
        <Col xs={12}>
            <section style={{ border: "1px solid #e9ebec", borderRadius: 16, padding: "16px 18px", background: "#fff" }}>
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Performance
                        </p>
                        <h5 className="mb-1 fw-semibold">Leitura rapida de marketing</h5>
                    </div>
                </div>

                <Row className="align-items-center g-3">
                    <Col xl={4} lg={5}>
                        {hasMarketingSignals ? (
                            <ReactApexChart series={series} options={options} type="donut" height={220} />
                        ) : (
                            <div className="text-center py-4 text-muted">
                                <i className="ri-pie-chart-line fs-1 d-block mb-2" />
                                <p className="mb-0 fs-13">Sem sinais de marketing</p>
                            </div>
                        )}
                    </Col>
                    <Col xl={8} lg={7}>
                        <div className="row g-2">
                            <div className="col-md-4">
                                <Metric label="Views" value={views.toLocaleString("pt-PT")} />
                            </div>
                            <div className="col-md-4">
                                <Metric label="Leads" value={leads.toLocaleString("pt-PT")} />
                            </div>
                            <div className="col-md-4">
                                <Metric label="Conversao" value={`${conversion.toFixed(2)}%`} />
                            </div>
                        </div>
                        {hasMarketingSignals && (
                            <div className="d-flex flex-wrap gap-2 mt-3">
                                {distribution.slice(0, 4).map((item, index) => (
                                    <span key={`${item.label}-${index}`} className="badge bg-light text-dark px-3 py-2">
                                        {item.label} {item.percentage.toFixed(1)}%
                                    </span>
                                ))}
                            </div>
                        )}
                    </Col>
                </Row>
            </section>
        </Col>
    );
}

function Metric({ label, value }: { label: string; value: string }) {
    return (
        <div className="d-flex align-items-center justify-content-between rounded-3 bg-light-subtle" style={{ padding: "14px 16px" }}>
            <span className="text-muted fs-13">{label}</span>
            <span className="fw-semibold">{value}</span>
        </div>
    );
}
