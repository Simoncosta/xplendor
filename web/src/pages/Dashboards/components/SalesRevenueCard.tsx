import { useEffect, useMemo, useState } from "react";
import ReactApexChart from "react-apexcharts";
import { Card, CardBody, Col } from "reactstrap";
import getChartColorsArray from "Components/Common/ChartsDynamicColor";
import { useIsMobile } from "../../../hooks/useIsMobile";
import type {
    SalesRevenue,
    SalesRevenueGranularity,
    SalesRevenuePreset,
} from "../../../types/api";

/**
 * Visão 3 do Dashboard (2026-06-25) — **FATURAÇÃO** (NÃO É LUCRO).
 *
 * Sem `purchase_price` em prod — lucro real só existirá quando essa faixa
 * entrar. Rótulos: *"Vendas no período"*, *"Faturação"*. Nunca *"Lucro"*.
 *
 * 4 presets + opção "Personalizado" com `<input type="month">` nativos (sem
 * biblioteca de date picker — decisão Simon). Trocar preset → novo fetch
 * via callback `onRangeChange`. Loading state limpa o valor velho antes de
 * mostrar o novo.
 */

interface Props {
    data: SalesRevenue | null;
    loading?: boolean;
    onRangeChange: (range: { from: string; to: string; granularity: SalesRevenueGranularity }) => void;
}

interface PresetRange {
    from: string;
    to: string;
    granularity: SalesRevenueGranularity;
}

/** YYYY-MM-DD a partir de um Date local. */
const fmtDate = (d: Date): string => {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
};

const lastDayOfMonth = (year: number, monthIdx0: number): number =>
    new Date(year, monthIdx0 + 1, 0).getDate();

const computePresetRange = (preset: SalesRevenuePreset, today: Date = new Date()): PresetRange => {
    const y = today.getFullYear();
    const m = today.getMonth(); // 0-based

    switch (preset) {
        case "this_month":
            return {
                from: fmtDate(new Date(y, m, 1)),
                to: fmtDate(new Date(y, m, lastDayOfMonth(y, m))),
                granularity: "month",
            };
        case "last_month": {
            const ly = m === 0 ? y - 1 : y;
            const lm = m === 0 ? 11 : m - 1;
            return {
                from: fmtDate(new Date(ly, lm, 1)),
                to: fmtDate(new Date(ly, lm, lastDayOfMonth(ly, lm))),
                granularity: "month",
            };
        }
        case "this_quarter": {
            const qStart = Math.floor(m / 3) * 3;
            const qEnd = qStart + 2;
            return {
                from: fmtDate(new Date(y, qStart, 1)),
                to: fmtDate(new Date(y, qEnd, lastDayOfMonth(y, qEnd))),
                granularity: "month",
            };
        }
        case "this_year":
            return {
                from: fmtDate(new Date(y, 0, 1)),
                to: fmtDate(new Date(y, 11, 31)),
                granularity: "month",
            };
        case "custom":
            // Custom — o componente trata; este caminho não deve ser chamado.
            return {
                from: fmtDate(new Date(y, m, 1)),
                to: fmtDate(today),
                granularity: "month",
            };
    }
};

const PRESET_LABELS: Record<SalesRevenuePreset, string> = {
    this_month:    "Este mês",
    last_month:    "Mês anterior",
    this_quarter:  "Este trimestre",
    this_year:     "Este ano",
    custom:        "Personalizado",
};

/** Formata YYYY-MM como "Jan 2026", YYYY como "2026". */
const formatBucketLabel = (period: string): string => {
    if (/^\d{4}$/.test(period)) return period;
    const m = /^(\d{4})-(\d{2})$/.exec(period);
    if (!m) return period;
    const year = Number(m[1]);
    const monthIdx0 = Number(m[2]) - 1;
    const monthShort = new Date(year, monthIdx0, 1).toLocaleDateString("pt-PT", { month: "short" });
    return `${monthShort.charAt(0).toUpperCase() + monthShort.slice(1)} ${year}`;
};

const formatCurrency = (value: number): string =>
    new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(value);

const SalesRevenueCard = ({ data, loading = false, onRangeChange }: Props) => {
    const isMobile = useIsMobile(680);
    const [preset, setPreset] = useState<SalesRevenuePreset>("this_year");

    // Custom range — YYYY-MM (input type="month").
    const todayMonthStr = useMemo(() => {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
    }, []);
    const [customFromMonth, setCustomFromMonth] = useState<string>(todayMonthStr);
    const [customToMonth, setCustomToMonth] = useState<string>(todayMonthStr);

    // Default: "Este ano" no primeiro mount → dispara fetch único.
    useEffect(() => {
        const range = computePresetRange("this_year");
        onRangeChange(range);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const applyPreset = (next: SalesRevenuePreset) => {
        setPreset(next);
        if (next === "custom") return; // espera o "Aplicar"
        const range = computePresetRange(next);
        onRangeChange(range);
    };

    const applyCustom = () => {
        // Converte YYYY-MM em primeiro dia + último dia do range.
        const fm = /^(\d{4})-(\d{2})$/.exec(customFromMonth);
        const tm = /^(\d{4})-(\d{2})$/.exec(customToMonth);
        if (!fm || !tm) return;
        const fy = Number(fm[1]); const fmi = Number(fm[2]) - 1;
        const ty = Number(tm[1]); const tmi = Number(tm[2]) - 1;
        const from = fmtDate(new Date(fy, fmi, 1));
        const to = fmtDate(new Date(ty, tmi, lastDayOfMonth(ty, tmi)));
        if (from > to) return; // ignora inversão
        onRangeChange({ from, to, granularity: "month" });
    };

    // ── Apex ──────────────────────────────────────────────────────────────
    const buckets = data?.buckets ?? [];
    const hasData = buckets.length > 0;
    const categories = buckets.map((b) => formatBucketLabel(b.period));
    const series = [{ name: "Faturação", data: buckets.map((b) => Number(b.revenue) || 0) }];

    const chartOptions: ApexCharts.ApexOptions = {
        chart: { type: "bar", height: 300, toolbar: { show: false }, parentHeightOffset: 0 },
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: buckets.length <= 4 ? "35%" : "60%",
                borderRadius: 4,
                dataLabels: { position: "top" },
            },
        },
        dataLabels: {
            enabled: !isMobile && buckets.length <= 12,
            offsetY: -18,
            style: { fontSize: "10px", colors: ["#495057"], fontWeight: 600 },
            formatter: (val) => {
                const n = Number(val);
                return n > 0 ? formatCurrency(n) : "";
            },
        },
        colors: getChartColorsArray('["--vz-success"]'),
        xaxis: {
            categories,
            labels: { style: { fontSize: "11px", colors: "#878a99" } },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            labels: {
                style: { fontSize: "11px", colors: "#878a99" },
                formatter: (val) => formatCurrency(Number(val)),
            },
        },
        grid: { borderColor: "#e9ebec", strokeDashArray: 3, padding: { top: 24 } },
        tooltip: {
            y: { formatter: (val) => formatCurrency(Number(val)) },
            custom: ({ dataPointIndex }) => {
                const b = buckets[dataPointIndex];
                if (!b) return "";
                const sales = b.sales_count;
                const salesText = `${sales} venda${sales === 1 ? "" : "s"}`;
                return `
                    <div class="px-2 py-2" style="font-size:12px;">
                        <div class="fw-semibold">${formatBucketLabel(b.period)}</div>
                        <div>${formatCurrency(Number(b.revenue) || 0)}</div>
                        <div class="text-muted">${salesText}</div>
                    </div>
                `;
            },
        },
        legend: { show: false },
    };

    const totalRevenue = data?.total_revenue ?? 0;
    const salesCount = data?.sales_count ?? 0;
    const withoutValue = data?.sales_without_value_count ?? 0;
    const rangeLabel = preset === "custom" && data
        ? `${data.range.from} → ${data.range.to}`
        : PRESET_LABELS[preset];

    return (
        <Col xs={12}>
            <Card className="mb-0">
                <CardBody>
                    <div className="d-flex flex-wrap justify-content-between gap-2 mb-3">
                        <div>
                            <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                Faturação
                            </p>
                            <h5 className="mb-0 fw-semibold">Vendas no período</h5>
                            <small className="text-muted">Valor das vendas registadas (não é lucro).</small>
                        </div>
                        <div className="d-flex flex-wrap gap-1 align-items-start">
                            {(["this_month", "last_month", "this_quarter", "this_year", "custom"] as SalesRevenuePreset[]).map((p) => (
                                <button
                                    key={p}
                                    type="button"
                                    className={`btn btn-sm ${preset === p ? "btn-primary" : "btn-soft-secondary"}`}
                                    onClick={() => applyPreset(p)}
                                    disabled={loading}
                                >
                                    {PRESET_LABELS[p]}
                                </button>
                            ))}
                        </div>
                    </div>

                    {preset === "custom" && (
                        <div className="d-flex flex-wrap gap-2 align-items-end mb-3 p-3 rounded" style={{ background: "#f8f9fa" }}>
                            <div>
                                <label className="form-label text-muted fs-12 mb-1">De (mês)</label>
                                <input
                                    type="month"
                                    className="form-control form-control-sm"
                                    value={customFromMonth}
                                    onChange={(e) => setCustomFromMonth(e.target.value)}
                                    max={customToMonth}
                                />
                            </div>
                            <div>
                                <label className="form-label text-muted fs-12 mb-1">Até (mês)</label>
                                <input
                                    type="month"
                                    className="form-control form-control-sm"
                                    value={customToMonth}
                                    onChange={(e) => setCustomToMonth(e.target.value)}
                                    min={customFromMonth}
                                />
                            </div>
                            <button type="button" className="btn btn-sm btn-primary" onClick={applyCustom} disabled={loading}>
                                Aplicar
                            </button>
                        </div>
                    )}

                    {/* Destaque com o total */}
                    <div className="d-flex flex-wrap align-items-baseline gap-3 mb-3 pb-3 border-bottom">
                        <div>
                            <p className="text-muted fs-12 mb-1">{rangeLabel}</p>
                            <h2 className="mb-0 fw-bold" style={{ color: "#0ab39c" }}>
                                {loading ? "—" : formatCurrency(Number(totalRevenue) || 0)}
                            </h2>
                        </div>
                        <div className="ms-auto text-end">
                            <p className="text-muted fs-12 mb-1">Vendas</p>
                            <p className="mb-0 fw-semibold">{loading ? "—" : `${salesCount} venda${salesCount === 1 ? "" : "s"}`}</p>
                            {!loading && withoutValue > 0 && (
                                <small className="text-warning d-block mt-1">
                                    <i className="ri-information-line me-1" />
                                    {withoutValue} sem valor registado
                                </small>
                            )}
                        </div>
                    </div>

                    {loading && (
                        <div className="text-center text-muted py-5">
                            <i className="ri-loader-2-line spin fs-3" /> A carregar…
                        </div>
                    )}

                    {!loading && !hasData && (
                        <div className="text-center text-muted py-5">
                            <i className="ri-funds-line fs-3 d-block mb-2" />
                            Sem vendas neste período.
                        </div>
                    )}

                    {!loading && hasData && (
                        <ReactApexChart
                            options={chartOptions}
                            series={series}
                            type="bar"
                            height={300}
                        />
                    )}
                </CardBody>
            </Card>
        </Col>
    );
};

export default SalesRevenueCard;
