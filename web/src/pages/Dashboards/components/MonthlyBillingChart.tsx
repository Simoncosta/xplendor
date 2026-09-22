import { useCallback, useEffect, useMemo, useState } from "react";
import ReactApexChart from "react-apexcharts";
import { Card, CardBody, Col } from "reactstrap";
import getChartColorsArray from "Components/Common/ChartsDynamicColor";
import { getPingwinMonthlyBilling } from "helpers/laravel_helper";
import { PingwinMonthlyBilling } from "common/models/pingwin.model";

/**
 * XPLENDOR — Gráfico de faturação MENSAL por restaurante (Balance Overview do
 * Velzon, ApexCharts linha). UMA linha por loja; X = 12 meses; filtro = ano.
 * ⚠️ Portão de honestidade: meses sem sync vêm null → a linha NÃO desce a 0
 * (connectNulls:false). Cores da paleta do tema (theme-aware, claro/escuro).
 */

const MONTHS_PT = ["Jan", "Fev", "Mar", "Abr", "Mai", "Jun", "Jul", "Ago", "Set", "Out", "Nov", "Dez"];

// Botões de período (padrão Velzon "1M 6M 1Y ALL", em PT). `months` = nº de meses
// finais do ano visíveis. 1A por defeito. Puramente de apresentação (fatia local,
// não mexe nos dados nem no fetch).
const RANGES = [
    { key: "ALL", label: "Tudo", months: 12 },
    { key: "1M", label: "1M", months: 1 },
    { key: "6M", label: "6M", months: 6 },
    { key: "1A", label: "1A", months: 12 },
] as const;
type RangeKey = (typeof RANGES)[number]["key"];

// Paleta de cores do TEMA (theme-aware via getChartColorsArray). Cicla por loja.
const PALETTE = ["--vz-primary", "--vz-success", "--vz-warning", "--vz-danger", "--vz-info", "--vz-secondary", "--vz-dark"];

const euro = (v: number) =>
    new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(Number(v) || 0);

export default function MonthlyBillingChart() {
    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

    const thisYear = new Date().getFullYear();
    const [year, setYear] = useState<number>(thisYear);
    const [range, setRange] = useState<RangeKey>("1A"); // ⚠️ 1 ano por defeito
    const [data, setData] = useState<PingwinMonthlyBilling | null>(null);
    const [loading, setLoading] = useState(false);

    const fetchData = useCallback(async (y: number) => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinMonthlyBilling(companyId, y);
            setData(res?.data ?? null);
        } catch {
            setData(null);
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => { fetchData(year); }, [fetchData, year]);

    // Fatia os últimos N meses conforme o botão de período (1A/Tudo = 12 meses).
    const visibleMonths = RANGES.find((r) => r.key === range)?.months ?? 12;
    const startIdx = Math.max(0, 12 - visibleMonths);
    const categories = MONTHS_PT.slice(startIdx);
    const series = (data?.series ?? []).map((s) => ({ name: s.name, data: s.data.slice(startIdx) }));
    const hasSeries = series.length > 0;
    // Uma cor por loja (cicla a paleta se houver mais lojas que cores).
    const colorVars = series.map((_, i) => PALETTE[i % PALETTE.length]);
    const colors = getChartColorsArray(JSON.stringify(colorVars.length ? colorVars : ["--vz-primary"]));

    const options: ApexCharts.ApexOptions = {
        // ⚠️ zoom desligado → o gráfico fica ESTÁTICO (não amplia ao fazer scroll).
        chart: { type: "line", height: 340, toolbar: { show: false }, zoom: { enabled: false }, parentHeightOffset: 0 },
        colors,
        stroke: { curve: "smooth", width: 2 },
        markers: { size: 4, hover: { size: 6 } },
        xaxis: {
            categories,
            labels: { style: { fontSize: "11px", colors: "#878a99" } },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            labels: {
                style: { fontSize: "11px", colors: "#878a99" },
                formatter: (val) => euro(Number(val)),
            },
        },
        grid: { borderColor: "var(--vz-border-color)", strokeDashArray: 3 },
        tooltip: { y: { formatter: (val) => (val === null ? "—" : euro(Number(val))) } },
        legend: { show: true, position: "top", horizontalAlign: "right", fontSize: "12px" },
        // ⚠️ Não ligar pontos através de meses sem dados (null) — honestidade.
        // (connectNulls fica false por defeito; deixado explícito.)
    };

    // Anos disponíveis para o filtro (corrente + 4 anteriores).
    const years = [0, 1, 2, 3, 4].map((n) => thisYear - n);

    return (
        <Col xs={12}>
            <Card className="mb-0">
                <CardBody>
                    <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div>
                            <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>Faturação mensal</p>
                            <h5 className="mb-0 fw-semibold">Evolução por restaurante</h5>
                        </div>
                        <div className="d-flex align-items-center gap-2">
                            <div className="btn-group btn-group-sm" role="group" aria-label="Período">
                                {RANGES.map((r) => (
                                    <button
                                        key={r.key}
                                        type="button"
                                        className={"btn " + (range === r.key ? "btn-primary" : "btn-outline-primary")}
                                        onClick={() => setRange(r.key)}
                                    >
                                        {r.label}
                                    </button>
                                ))}
                            </div>
                            <select className="form-select form-select-sm" style={{ width: 120 }} value={year} onChange={(e) => setYear(Number(e.target.value))} disabled={loading}>
                                {years.map((y) => <option key={y} value={y}>{y}</option>)}
                            </select>
                        </div>
                    </div>

                    {!hasSeries ? (
                        <div className="text-center text-muted py-5">
                            <i className="ri-line-chart-line fs-3 d-block mb-2" />
                            Sem lojas para mostrar.
                        </div>
                    ) : (
                        <ReactApexChart options={options} series={series} type="line" height={340} />
                    )}
                </CardBody>
            </Card>
        </Col>
    );
}
