import React from "react";
import ReactApexChart from "react-apexcharts";
import getChartColorsArray from "Components/Common/ChartsDynamicColor";
import { Card, CardBody, Col } from "reactstrap";

interface ICarChartItem {
    id: number;
    version?: string | null;
    views_count: number;
    leads_count: number;
    interactions_count: number;
    demand_score?: number;
    brand_name?: string;
    model_name?: string;
    brand?: {
        id: number;
        name: string;
    };
    model?: {
        id: number;
        name: string;
    };
}

interface HighDemandOpportunityChartProps {
    dataColors?: string;
    data: ICarChartItem[];
}

const HighDemandOpportunityChart = ({
    dataColors = '["--vz-primary", "--vz-success", "--vz-warning"]',
    data,
}: HighDemandOpportunityChartProps) => {
    const colors = getChartColorsArray(dataColors);

    const categories = data.map((car) =>
        `${car.brand?.name ?? ""} ${car.model?.name ?? ""}`
    );

    const series = [
        {
            name: "Views",
            data: data.map((car) => car.views_count),
        },
        {
            name: "Leads",
            data: data.map((car) => car.leads_count),
        },
        {
            name: "Interações",
            data: data.map((car) => car.interactions_count),
        },
    ];

    const options: ApexCharts.ApexOptions = {
        chart: {
            type: "bar",
            height: 420,
            toolbar: {
                show: false,
            },
        },

        plotOptions: {
            bar: {
                horizontal: true,
                borderRadius: 4,
                barHeight: "60%",
            },
        },

        colors: colors,

        dataLabels: {
            enabled: true,
        },

        xaxis: {
            categories,
            labels: {
                formatter: (val: string) => Number(val).toLocaleString("pt-PT"),
            },
        },

        legend: {
            position: "bottom",
            horizontalAlign: "center",
            fontSize: "13px",
            markers: {
                // radius: 4
            },
            itemMargin: {
                horizontal: 10,
                vertical: 4
            }
        },

        grid: {
            borderColor: "#f1f1f1",
        },

        tooltip: {
            y: {
                formatter: (val: number) =>
                    val.toLocaleString("pt-PT"),
            },
        },
    };

    return (
        <React.Fragment>
            <Col xl={6}>
                <Card className="overflow-hidden">
                    <div className="card-header align-items-center d-flex">
                        <h4 className="card-title mb-0 flex-grow-1">🔥 Carros com maior procura</h4>
                    </div>
                    <div>
                        {
                            data.length > 0 ? (
                                <ReactApexChart
                                    dir="ltr"
                                    options={options}
                                    series={series}
                                    type="bar"
                                    height={280}
                                    className="apex-charts"
                                />
                            ) : (
                                <div className="p-3">
                                    <p className="fs-16 lh-base">
                                        Ainda não há dados suficientes para identificar carros com maior procura.
                                        Assim que o stock começar a receber mais visitas e interações, este gráfico será preenchido automaticamente.
                                    </p>
                                </div>
                            )
                        }
                    </div>
                </Card>
            </Col>
        </React.Fragment>
    );
};

export default HighDemandOpportunityChart;