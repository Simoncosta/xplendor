import ReactApexChart from "react-apexcharts";
import { Card, CardBody, Col, Row } from "reactstrap";
import getChartColorsArray from "Components/Common/ChartsDynamicColor";
import { useIsMobile } from "../../../hooks/useIsMobile";
import { labelOf, VEHICLE_TYPE_LABELS } from "../../../helpers/labels";
import type { StockBreakdown } from "../../../types/api";

/**
 * Visões 1+2 do Dashboard (2026-06-25) — stock por marca + tipo de veículo.
 *
 * 2 gráficos lado a lado em desktop, empilhados em mobile (`useIsMobile(992)`).
 *  - Marcas: bar horizontal Apex, ordenado por contagem (backend já entrega
 *    desc). Top 10 visíveis para evitar gráfico ilegível em stands grandes;
 *    se houver mais, mostra-se o total textual abaixo.
 *  - Tipos: donut. Labels traduzidos via `VEHICLE_TYPE_LABELS`
 *    (carro/mota/autocaravana/caravana).
 *
 * Tipos próprios em `types/api.ts` (sec 10 — fim do `any`).
 */
const MAX_BRANDS_VISIBLE = 10;

interface Props {
    data: StockBreakdown | null;
    loading?: boolean;
}

const StockBreakdownCard = ({ data, loading = false }: Props) => {
    const isStacked = useIsMobile(992);

    const byBrand = data?.by_brand ?? [];
    const byType = data?.by_type ?? [];
    const hasBrands = byBrand.length > 0;
    const hasTypes = byType.length > 0;
    const hasAny = hasBrands || hasTypes;

    // ── Bar marcas ────────────────────────────────────────────────────────
    const brandsVisible = byBrand.slice(0, MAX_BRANDS_VISIBLE);
    const brandHiddenCount = Math.max(0, byBrand.length - MAX_BRANDS_VISIBLE);
    const brandTotal = byBrand.reduce((acc, row) => acc + row.count, 0);

    const brandChartHeight = Math.max(220, 36 * brandsVisible.length + 40);
    const brandSeries = [{ name: "Viaturas", data: brandsVisible.map((r) => r.count) }];
    const brandOptions: ApexCharts.ApexOptions = {
        chart: { type: "bar", height: brandChartHeight, toolbar: { show: false }, parentHeightOffset: 0 },
        plotOptions: {
            bar: {
                horizontal: true,
                barHeight: "70%",
                borderRadius: 4,
                dataLabels: { position: "top" },
            },
        },
        dataLabels: {
            enabled: true,
            offsetX: 28,
            style: { fontSize: "11px", colors: ["#495057"], fontWeight: 600 },
            formatter: (val) => String(val),
        },
        colors: getChartColorsArray('["--vz-primary"]'),
        xaxis: {
            categories: brandsVisible.map((r) => r.name),
            labels: { style: { fontSize: "11px", colors: "#878a99" } },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: { labels: { style: { fontSize: "12px", colors: "#495057" } } },
        grid: { borderColor: "#e9ebec", strokeDashArray: 3, padding: { right: 24 } },
        tooltip: {
            y: { formatter: (val) => `${val} viatura${val === 1 ? "" : "s"}` },
        },
        legend: { show: false },
    };

    // ── Donut tipos ───────────────────────────────────────────────────────
    const typeSeries = byType.map((r) => r.count);
    const typeLabels = byType.map((r) => labelOf(r.type, VEHICLE_TYPE_LABELS) ?? r.type);
    const typeTotal = byType.reduce((acc, row) => acc + row.count, 0);

    const typeOptions: ApexCharts.ApexOptions = {
        chart: { type: "donut", height: 280, toolbar: { show: false } },
        labels: typeLabels,
        colors: getChartColorsArray('["--vz-primary", "--vz-success", "--vz-warning", "--vz-info"]'),
        legend: { position: "bottom", fontSize: "12px", markers: { strokeWidth: 0 } },
        dataLabels: { enabled: false },
        stroke: { width: 0 },
        plotOptions: {
            pie: {
                donut: {
                    size: "70%",
                    labels: {
                        show: true,
                        total: {
                            show: true,
                            label: "Total",
                            fontSize: "12px",
                            color: "#878a99",
                            formatter: () => String(typeTotal),
                        },
                        value: {
                            fontSize: "20px",
                            fontWeight: 600,
                            color: "#495057",
                            formatter: (val) => String(val),
                        },
                    },
                },
            },
        },
        tooltip: {
            y: { formatter: (val) => `${val} viatura${val === 1 ? "" : "s"}` },
        },
    };

    return (
        <Col xs={12}>
            <Card className="mb-0">
                <CardBody>
                    <div className="mb-3">
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Composição do stock
                        </p>
                        <h5 className="mb-0 fw-semibold">Por marca e tipo de veículo</h5>
                    </div>

                    {loading && (
                        <div className="text-center text-muted py-5">
                            <i className="ri-loader-2-line spin fs-3" /> A carregar…
                        </div>
                    )}

                    {!loading && !hasAny && (
                        <div className="text-center text-muted py-5">
                            <i className="ri-archive-drawer-line fs-3 d-block mb-2" />
                            Sem viaturas em stock para mostrar.
                        </div>
                    )}

                    {!loading && hasAny && (
                        <Row className="g-4">
                            {/* Marcas */}
                            <Col lg={isStacked ? 12 : 7}>
                                <div className="d-flex justify-content-between align-items-baseline mb-2">
                                    <h6 className="fw-semibold mb-0">Marcas</h6>
                                    <small className="text-muted">
                                        {brandTotal} viatura{brandTotal === 1 ? "" : "s"}
                                        {brandHiddenCount > 0 && (
                                            <> · top {MAX_BRANDS_VISIBLE} de {byBrand.length} marcas</>
                                        )}
                                    </small>
                                </div>
                                {hasBrands ? (
                                    <ReactApexChart
                                        options={brandOptions}
                                        series={brandSeries}
                                        type="bar"
                                        height={brandChartHeight}
                                    />
                                ) : (
                                    <div className="text-muted small">Sem marcas em stock.</div>
                                )}
                            </Col>

                            {/* Tipos */}
                            <Col lg={isStacked ? 12 : 5}>
                                <div className="d-flex justify-content-between align-items-baseline mb-2">
                                    <h6 className="fw-semibold mb-0">Tipos de veículo</h6>
                                    <small className="text-muted">{byType.length} tipo{byType.length === 1 ? "" : "s"}</small>
                                </div>
                                {hasTypes ? (
                                    <ReactApexChart
                                        options={typeOptions}
                                        series={typeSeries}
                                        type="donut"
                                        height={280}
                                    />
                                ) : (
                                    <div className="text-muted small">Sem tipos em stock.</div>
                                )}
                            </Col>
                        </Row>
                    )}
                </CardBody>
            </Card>
        </Col>
    );
};

export default StockBreakdownCard;
