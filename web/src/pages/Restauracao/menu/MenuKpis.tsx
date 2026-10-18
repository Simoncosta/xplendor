import ReactApexChart from "react-apexcharts";
import { Badge, Card, CardBody, Col, Progress, Row } from "reactstrap";
import InfoTip from "Components/Common/InfoTip";
import { MenuClass, MenuSnapshot } from "common/models/menuEngineering.model";
import { CLASS_META, CONFIDENCE_META, fmtPct, joinPt, tokenColor, topActions } from "./menuFormat";

/**
 * XPLENDOR — Fatia 3: os 4 indicadores do topo da engenharia de menu (o mesmo tamanho; 2 × 2 no
 * telemóvel). Cada um diz o número, de onde vem (InfoTip) e o que fazer a seguir.
 */
function Kpi({ title, tip, children, testid }: { title: string; tip: string; children: React.ReactNode; testid: string }) {
    return (
        <Card className="h-100 mb-0" data-testid={testid}>
            <CardBody style={{ padding: "1.25rem" }}>
                <div className="text-muted fs-12 text-uppercase fw-semibold d-flex align-items-center gap-1 mb-2">{title}<InfoTip label={title} text={tip} /></div>
                {children}
            </CardBody>
        </Card>
    );
}

const QUADRANTS: MenuClass[][] = [["quebra_cabecas", "estrela"], ["cao", "cavalo"]];

export default function MenuKpis({ snapshot, target, confirmedTarget }: { snapshot: MenuSnapshot; target: number; confirmedTarget: number }) {
    const s = snapshot.summary;
    const cov = s.coverage;
    const action = topActions(snapshot.worklist, cov.confirmado_pct);
    const fc = s.food_cost_purchases?.avg3_pct ?? null;
    const fcOk = fc !== null && fc <= target;
    const months = snapshot.food_cost.months.filter((m) => m.complete && m.avg3_pct !== null).slice(-6);
    const provisional = (cov.confirmado_pct ?? 0) < confirmedTarget;

    return (
        <Row className="g-3 mb-3" data-testid="menu-kpis">
            <Col xs={6} xl={3}>
                <Kpi testid="menu-kpi-coverage" title="Custo confirmado"
                    tip="A parte da receita sem IVA de pratos com o custo confirmado: ficha técnica e todos os ingredientes com preço de compra e conversão. Parcial: falta o preço de algum ingrediente (o custo é um mínimo). Sem custo: sem ficha técnica.">
                    <div className="fs-3 fw-semibold lh-1 mb-2">{fmtPct(cov.confirmado_pct)}</div>
                    <Progress multi className="mb-2" style={{ height: 8 }} aria-label="Receita por confiança do custo">
                        <Progress bar color="success" value={cov.confirmado_pct ?? 0} />
                        <Progress bar color="warning" value={cov.parcial_pct ?? 0} />
                        <Progress bar color="secondary" value={cov.sem_custo_pct ?? 0} />
                    </Progress>
                    <div className="fs-11 text-muted mb-2">
                        {(["confirmado", "parcial", "sem_custo"] as const).map((c) => (
                            <span key={c} className="me-2 text-nowrap"><i className="ri-checkbox-blank-circle-fill me-1" style={{ color: CONFIDENCE_META[c].cssVar }} />{CONFIDENCE_META[c].label} {fmtPct(cov[`${c}_pct` as const])}</span>
                        ))}
                    </div>
                    {action && action.targetPct !== null && action.targetPct > (cov.confirmado_pct ?? 0) && (
                        <div className="fs-12" data-testid="menu-kpi-action"><i className="ri-arrow-right-line text-primary me-1" />Confirmar {joinPt(action.phrases)} leva para ≈ {fmtPct(action.targetPct, 0)}.</div>
                    )}
                </Kpi>
            </Col>
            <Col xs={6} xl={3}>
                <Kpi testid="menu-kpi-purchases" title="Food cost por compras"
                    tip="O que se comprou de comida e de bebida (faturas de fornecedor, menos notas de crédito) a dividir pelo que se vendeu sem IVA, nos 3 meses completos mais recentes, na empresa toda. Não precisa de fichas técnicas.">
                    <div className="d-flex flex-wrap align-items-end justify-content-between gap-2">
                        <div className={`fs-3 fw-semibold lh-1 ${fc === null ? "" : fcOk ? "text-success" : "text-warning"}`}>{fmtPct(fc)}</div>
                        {months.length > 1 && (
                            <div style={{ width: "100%", maxWidth: 120, minWidth: 0 }} aria-hidden data-testid="menu-kpi-sparkline">
                                <ReactApexChart type="line" height={40} width="100%" series={[{ name: "Média de 3 meses", data: months.map((m) => m.avg3_pct ?? 0) }]}
                                    options={{ chart: { sparkline: { enabled: true }, animations: { enabled: false } }, stroke: { width: 2, curve: "smooth" },
                                        colors: [tokenColor(fcOk ? "var(--vz-success)" : "var(--vz-warning)")], tooltip: { enabled: false } } as any} />
                            </div>
                        )}
                    </div>
                    <div className="fs-12 text-muted mt-2">{s.food_cost_purchases ? `Média de 3 meses até ${s.food_cost_purchases.month.slice(5)}/${s.food_cost_purchases.month.slice(0, 4)}` : "Ainda sem 3 meses completos"}</div>
                    {fc !== null && (
                        <div className={`fs-12 mt-1 ${fcOk ? "text-success" : "text-warning"}`}>
                            <i className={`${fcOk ? "ri-checkbox-circle-line" : "ri-error-warning-line"} me-1`} />{fcOk ? "Abaixo" : "Acima"} do alvo de {fmtPct(target, 0)}
                        </div>
                    )}
                </Kpi>
            </Col>
            <Col xs={6} xl={3}>
                <Kpi testid="menu-kpi-theory" title="Food cost teórico"
                    tip="O custo das fichas técnicas × a quantidade vendida, a dividir pela receita, dos pratos com custo (Confirmado ou Parcial). Nos Parciais entra o custo mínimo, por isso o valor pode estar abaixo do real.">
                    <div className="fs-3 fw-semibold lh-1 mb-2">{fmtPct(s.food_cost_theoretical.pct)}</div>
                    {provisional && <Badge color="warning-subtle" className="text-warning fs-11 mb-2 text-wrap text-start lh-base" data-testid="menu-kpi-provisional">Provisório: inclui custos parciais</Badge>}
                    <div className="fs-12 text-muted">Calculado sobre {fmtPct(s.food_cost_theoretical.coverage_pct)} da receita (a que tem custo).</div>
                </Kpi>
            </Col>
            <Col xs={6} xl={3}>
                <Kpi testid="menu-kpi-classes" title="Classes"
                    tip="Os pratos na matriz com custo, por classe (Kasavana e Smith, dentro de cada família): em cima, os que deixam mais margem; à direita, os mais vendidos.">
                    <div className="d-grid gap-1" style={{ gridTemplateColumns: "minmax(0, 1fr) minmax(0, 1fr)" }} data-testid="menu-kpi-mini-matrix">
                        {QUADRANTS.flat().map((c) => (
                            <div key={c} className={`rounded px-2 py-1 bg-${CLASS_META[c].color}-subtle text-${CLASS_META[c].color}`} style={{ minWidth: 0 }} title={CLASS_META[c].label}>
                                <div className="fs-5 fw-semibold lh-1">{s.classes[c] ?? 0}</div>
                                <div className="fs-11 lh-sm text-wrap" style={{ overflowWrap: "anywhere" }}>{CLASS_META[c].label}</div>
                            </div>
                        ))}
                    </div>
                </Kpi>
            </Col>
        </Row>
    );
}
