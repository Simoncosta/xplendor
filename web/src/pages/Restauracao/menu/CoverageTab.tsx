import { useMemo, useState } from "react";
import ReactApexChart from "react-apexcharts";
import { useNavigate } from "react-router-dom";
import { Badge, Button, Col, Row, Table } from "reactstrap";
import { toast } from "react-toastify";
import InfoTip from "Components/Common/InfoTip";
import { getRecipeConversionInfo } from "helpers/laravel_helper";
import { MenuSnapshot, WorkAction } from "common/models/menuEngineering.model";
import ConfirmFactorModal, { FactorLine } from "../fichas/ConfirmFactorModal";
import { CLASS_META, CONFIDENCE_META, fmtEur, fmtPct, tokenColor } from "./menuFormat";

/**
 * XPLENDOR — Fatia 3: cobertura de custos. A curva de Pareto da receita (cada barra é um artigo, com a
 * cor da confiança do custo) e a lista de trabalho para chegar a 80 % da receita com custo: criar a
 * ficha (editor das fichas), confirmar o fator (ConfirmFactorModal) ou ver o ingrediente na auditoria.
 */
const cssVar = (name: string, fallback: string) => {
    try {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    } catch {
        return fallback;
    }
};

export default function CoverageTab({ companyId, snapshot, hidden, onChanged }: { companyId: number; snapshot: MenuSnapshot; hidden: boolean; onChanged: () => void }) {
    const navigate = useNavigate();
    const [factor, setFactor] = useState<FactorLine | null>(null);
    const s = snapshot.summary;
    const points = snapshot.pareto.slice(0, 60);

    const chart = useMemo(() => {
        const fg = cssVar("--vz-body-color", "#495057");
        const conf = { confirmado: tokenColor(CONFIDENCE_META.confirmado.cssVar), parcial: tokenColor(CONFIDENCE_META.parcial.cssVar), sem_custo: tokenColor(CONFIDENCE_META.sem_custo.cssVar) };
        const line = tokenColor("var(--vz-primary)");
        return {
            series: [
                { name: "Receita sem IVA", type: "column", data: points.map((p) => p.revenue_eur ?? 0) },
                { name: "% acumulada", type: "line", data: points.map((p) => p.cum_pct) },
            ],
            options: {
                chart: { toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
                plotOptions: { bar: { distributed: false, columnWidth: "80%" } },
                colors: [({ dataPointIndex, seriesIndex }: any) => (seriesIndex === 0 ? conf[points[dataPointIndex]?.confidence ?? "sem_custo"] : line)],
                stroke: { width: [0, 2] },
                dataLabels: { enabled: false },
                legend: { show: false },
                xaxis: { categories: points.map((p) => p.name), labels: { show: false }, title: { text: `Os ${points.length} artigos com mais receita, por ordem` } },
                yaxis: [
                    { title: { text: hidden ? "" : "Receita (€)" }, labels: { show: !hidden, formatter: (v: number) => fmtEur(v, 0) } },
                    { opposite: true, max: 100, min: 0, title: { text: "% acumulada" }, labels: { formatter: (v: number) => `${Math.round(v)}%` } },
                ],
                annotations: { yaxis: [{ y: 80, yAxisIndex: 1, borderColor: fg, strokeDashArray: 4, label: { text: "80 %", style: { color: fg, background: "transparent" } } }] },
                tooltip: { shared: true, y: { formatter: (v: number, o: any) => (o?.seriesIndex === 1 ? fmtPct(v) : hidden ? "—" : fmtEur(v, 0)) } },
            },
        };
    }, [points, hidden]);

    const act = async (a: WorkAction) => {
        if (a.type === "criar_ficha") navigate(`/restauracao/fichas-tecnicas/${a.product_id}?editar=1`);
        else if (a.type === "ver_ficha") navigate(`/restauracao/fichas-tecnicas/${a.product_id}`);
        else if (a.type === "ver_auditoria") navigate(`/auditoria?area=restauracao&search=${encodeURIComponent(a.search ?? a.product_id)}`);
        else {
            try {
                const res: any = await getRecipeConversionInfo(companyId, a.product_id);
                if (res?.data?.line) setFactor(res.data.line);
                else toast.info("Este ingrediente não tem compras noutra embalagem.");
            } catch (e: any) {
                toast.error(e?.message ?? "Não foi possível abrir a confirmação do fator.");
            }
        }
    };

    return (
        <div data-testid="menu-coverage">
            <Row className="g-3">
                <Col xs={12} lg={8}>
                    <p className="mb-1 fs-14" data-testid="menu-pareto-text">
                        Os <strong>{fmtPct(s.pareto.top_articles_pct)}</strong> de artigos do topo ({s.pareto.top_articles} de {s.pareto.articles}) fazem <strong>80 %</strong> da receita.
                        <InfoTip label="Pareto" text="Os artigos ordenados pela receita sem IVA do período; cada barra tem a cor da confiança do custo (verde Confirmado, amarelo Parcial, cinzento Sem custo)." />
                    </p>
                    <div className="d-flex gap-3 fs-12 mb-1">
                        {(["confirmado", "parcial", "sem_custo"] as const).map((c) => <span key={c}><i className="ri-checkbox-blank-fill me-1" style={{ color: CONFIDENCE_META[c].cssVar }} />{CONFIDENCE_META[c].label}</span>)}
                    </div>
                    <ReactApexChart type="line" height={300} series={chart.series as any} options={chart.options as any} />
                </Col>
                <Col xs={12} lg={4}>
                    <h6 className="mb-2">Top 5 da receita</h6>
                    <Table size="sm" className="mb-0 fs-13">
                        <tbody>
                            {s.top5.map((t) => (
                                <tr key={t.product_id}>
                                    <td>{t.description}<div className="text-muted fs-11">{t.family}</div></td>
                                    <td className="text-end text-nowrap">{hidden ? "" : fmtEur(t.revenue_eur, 0)}
                                        <div>{t.class ? <Badge color={`${CLASS_META[t.class].color}-subtle`} className={`text-${CLASS_META[t.class].color} fs-10`}>{CLASS_META[t.class].label}</Badge> : null}{" "}
                                            <Badge color={`${CONFIDENCE_META[t.confidence].color}-subtle`} className={`text-${CONFIDENCE_META[t.confidence].color} fs-10`}>{CONFIDENCE_META[t.confidence].label}</Badge></div></td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Col>
            </Row>

            <h6 className="mt-4 mb-1">Para chegar a {fmtPct(s.coverage_target_pct, 0)} de cobertura</h6>
            <p className="text-muted fs-13 mb-2" data-testid="menu-fichas-to-target">
                {s.coverage.with_cost_pct !== null && s.coverage.with_cost_pct >= s.coverage_target_pct
                    ? `A receita com custo já é ${fmtPct(s.coverage.with_cost_pct)}.`
                    : `Faltam ${s.fichas_to_target} ${s.fichas_to_target === 1 ? "ficha técnica" : "fichas técnicas"} (pelos artigos com mais receita) para a receita com custo chegar a ${fmtPct(s.coverage_target_pct, 0)}; hoje é ${fmtPct(s.coverage.with_cost_pct)}.`}
            </p>
            <Table size="sm" responsive className="align-middle fs-13" data-testid="menu-worklist">
                <thead className="table-light"><tr><th>Prato</th><th>Confiança</th><th className="text-end">Receita</th><th>O que falta</th></tr></thead>
                <tbody>
                    {snapshot.worklist.slice(0, 40).map((w) => (
                        <tr key={w.product_id}>
                            <td>{w.description}<div className="text-muted fs-11">{w.family}</div></td>
                            <td><Badge color={`${CONFIDENCE_META[w.confidence].color}-subtle`} className={`text-${CONFIDENCE_META[w.confidence].color} fs-11`}>{CONFIDENCE_META[w.confidence].label}</Badge></td>
                            <td className="text-end text-nowrap">{hidden ? fmtPct(w.revenue_share_pct) : fmtEur(w.revenue_eur, 0)}</td>
                            <td>
                                {w.actions.map((a, i) => (
                                    <div key={i} className="d-flex align-items-center gap-2 mb-1">
                                        <Button size="sm" color={a.type === "criar_ficha" ? "primary" : "outline-primary"} className="text-nowrap" onClick={() => act(a)}>{a.label}</Button>
                                        <span className="fs-12 text-muted">{a.text}</span>
                                    </div>
                                ))}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </Table>
            {factor && <ConfirmFactorModal open line={factor} companyId={companyId} hidden={hidden} onClose={() => setFactor(null)} onDone={() => { setFactor(null); onChanged(); }} />}
        </div>
    );
}
