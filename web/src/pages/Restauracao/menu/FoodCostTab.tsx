import { useMemo } from "react";
import ReactApexChart from "react-apexcharts";
import { Alert, Table } from "reactstrap";
import InfoTip from "Components/Common/InfoTip";
import { MenuSnapshot } from "common/models/menuEngineering.model";
import { fmtEur, fmtPct, tokenColor } from "./menuFormat";

/**
 * XPLENDOR — Fatia 3: food cost por compras (não precisa de fichas). Por mês: comida, bebida, total e a
 * média móvel de 3 meses; só para a empresa toda. Ao lado, o teórico (dos artigos com custo), que só se
 * compara com o real quando a cobertura chega a 80 %.
 */
const MONTHS = ["jan", "fev", "mar", "abr", "mai", "jun", "jul", "ago", "set", "out", "nov", "dez"];
const monthLabel = (m: string) => `${MONTHS[Number(m.slice(5, 7)) - 1]} ${m.slice(2, 4)}`;
const cssVar = (name: string, fallback: string) => {
    try {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    } catch {
        return fallback;
    }
};

export default function FoodCostTab({ snapshot, hidden, target = 35 }: { snapshot: MenuSnapshot; hidden: boolean; target?: number }) {
    const months = snapshot.food_cost.months.filter((m) => (m.revenue_eur ?? 0) > 0 || m.total_pct !== null);
    const s = snapshot.summary;
    const chart = useMemo(() => ({
        series: [
            { name: "Comida", type: "column", data: months.map((m) => m.comida_pct) },
            { name: "Bebida", type: "column", data: months.map((m) => m.bebida_pct) },
            { name: "Total", type: "line", data: months.map((m) => m.total_pct) },
            { name: "Média de 3 meses", type: "line", data: months.map((m) => m.avg3_pct) },
        ],
        options: {
            chart: { stacked: false, toolbar: { show: false }, foreColor: cssVar("--vz-body-color", "#495057"), animations: { enabled: false } },
            colors: [tokenColor("var(--vz-success)"), tokenColor("var(--vz-info)"), tokenColor("var(--vz-primary)"), tokenColor("var(--vz-warning)")],
            annotations: { yaxis: [{ y: target, borderColor: tokenColor("var(--vz-danger)"), strokeDashArray: 4, label: { text: `Alvo ${target.toLocaleString("pt-PT")} %`, position: "left", textAnchor: "start", style: { color: tokenColor("var(--vz-danger)"), background: "transparent" } } }] },
            stroke: { width: [0, 0, 2, 3], dashArray: [0, 0, 0, 5] },
            dataLabels: { enabled: false },
            xaxis: { categories: months.map((m) => monthLabel(m.month) + (m.complete ? "" : " (parcial)")) },
            yaxis: { min: 0, labels: { formatter: (v: number) => `${Math.round(v)}%` }, title: { text: "% das vendas sem IVA" } },
            tooltip: { shared: true, y: { formatter: (v: number | null) => fmtPct(v) } },
            legend: { position: "bottom" },
        },
    }), [months, target]);

    return (
        <div data-testid="menu-foodcost">
            <Alert color="info" className="py-2 fs-13">
                <i className="ri-information-line me-1" />Por loja ainda não é possível: as transferências do Economato só são registadas a partir de agora.
            </Alert>
            <p className="fs-13 text-muted mb-2">
                Compras líquidas sem IVA (faturas e faturas-recibo de fornecedor, menos as notas de crédito) das famílias {Object.values(snapshot.food_cost.families).flat().join(" e ")}
                {" "}÷ vendas sem IVA da empresa toda.
                <InfoTip label="Food cost por compras" text={`Não precisa de fichas: é o que se comprou de comida e de bebida a dividir pelo que se vendeu, no mesmo mês. Ficam de fora: ${snapshot.food_cost.excluded_families.join(", ")}. Um mês com uma compra grande (um stock inicial, por exemplo) sobe; por isso a média de 3 meses é a melhor leitura.`} />
            </p>
            <ReactApexChart type="line" height={320} series={chart.series as any} options={chart.options as any} />
            <Table size="sm" responsive className="fs-13 mt-2">
                <thead className="table-light"><tr><th>Mês</th><th className="text-end">Vendas</th><th className="text-end">Comida</th><th className="text-end">Bebida</th><th className="text-end">Total</th><th className="text-end">Média de 3 meses</th></tr></thead>
                <tbody>
                    {months.map((m) => (
                        <tr key={m.month}>
                            <td>{monthLabel(m.month)}{m.complete ? "" : " (parcial)"}</td>
                            <td className="text-end">{hidden ? "—" : fmtEur(m.revenue_eur, 0)}</td>
                            <td className="text-end">{fmtPct(m.comida_pct)}{!hidden && <div className="fs-11 text-muted">{fmtEur(m.comida_eur, 0)}</div>}</td>
                            <td className="text-end">{fmtPct(m.bebida_pct)}{!hidden && <div className="fs-11 text-muted">{fmtEur(m.bebida_eur, 0)}</div>}</td>
                            <td className="text-end fw-medium">{fmtPct(m.total_pct)}</td>
                            <td className="text-end">{fmtPct(m.avg3_pct)}</td>
                        </tr>
                    ))}
                </tbody>
            </Table>
            <h6 className="mt-3">Real contra teórico</h6>
            {s.compare_allowed ? (
                <p className="fs-13" data-testid="menu-compare">
                    Por compras (média de 3 meses): <strong>{fmtPct(s.food_cost_purchases?.avg3_pct)}</strong>. Teórico (pelas fichas): <strong>{fmtPct(s.food_cost_theoretical.pct)}</strong>.
                    A diferença é o desperdício, as ofertas, os erros de stock e as fichas desatualizadas.
                    {(s.coverage.confirmado_pct ?? 0) < s.coverage_target_pct && <> Provisório: o teórico inclui custos parciais (só {fmtPct(s.coverage.confirmado_pct)} da receita tem o custo confirmado).</>}
                </p>
            ) : (
                <p className="fs-13 text-muted" data-testid="menu-compare-blocked">
                    A comparação só aparece com pelo menos {fmtPct(s.coverage_target_pct, 0)} da receita com custo (hoje {fmtPct(s.coverage.with_cost_pct)}). O teórico de {fmtPct(s.food_cost_theoretical.pct)} vale só para essa parte da receita.
                </p>
            )}
        </div>
    );
}
